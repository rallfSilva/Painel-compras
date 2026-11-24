<?php

namespace App\Services\Integracoes\Entrada;

use App\Jobs\ProcessarLoteIntegracao;
use App\Models\User;
use App\Services\Importacao\ImportacaoService;
use App\Services\Importacao\RegistroImportadores;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * API de entrada: outro sistema (NexPlanGov, sistema financeiro...) envia lotes JSON com os mesmos
 * tipos e campos da importação de arquivos. Cada lote vira uma importação de origem = código do
 * sistema, passa pela mesma validação, aparece no histórico com as inconsistências e é gravado
 * de forma idempotente (reenviar um registro atualiza, não duplica).
 *
 * A validação roda na fila, no momento do processamento, e não no recebimento: assim um lote de
 * resultados enviado logo depois do lote de processos encontra os processos já gravados, desde que
 * o sistema de origem respeite a ordem de dependência (ver ORDEM) e a fila tenha um único worker.
 */
class EntradaService
{
    public const LIMITE_REGISTROS = 5000;

    /** Ordem recomendada de envio: cada tipo só referencia os anteriores. */
    public const ORDEM = ['CLASSIFICACOES', 'UNIDADES', 'FORNECEDORES', 'CATALOGO', 'PCA', 'PROCESSOS', 'RESULTADOS', 'ATAS',
        'CONTRATOS', 'ADITIVOS', 'ATAS_UTILIZACOES', 'EXECUCAO_FINANCEIRA'];

    public function __construct(private readonly ImportacaoService $importacao) {}

    /** Tipos aceitos, com os campos (nome, rótulo, tipo, obrigatório, exemplo) e a ordem de envio. */
    public function tipos(): array
    {
        $ordem = array_flip(self::ORDEM);

        return collect($this->importacao->tipos())
            ->map(fn ($t) => $t + ['ordem_envio' => ($ordem[$t['codigo']] ?? 99) + 1])
            ->sortBy('ordem_envio')->values()->all();
    }

    /**
     * Recebe um lote e o coloca na fila. Com a mesma chave de idempotência, devolve o lote já recebido.
     *
     * @param  list<array<string, mixed>>  $registros
     * @return array{lote: array, novo: bool}
     */
    public function receber(User $sistema, string $origem, string $tipo, array $registros, ?string $chave, ?string $referencia): array
    {
        if ($chave !== null && ($id = DB::table('importacoes')->where('user_id', $sistema->id)->where('chave_idempotencia', $chave)->value('id'))) {
            return ['lote' => $this->lote($sistema, (int) $id), 'novo' => false];
        }
        $importador = RegistroImportadores::obter($tipo);
        $campos = array_keys($importador->campos());
        $desconhecidos = [];
        foreach ($registros as $i => $r) {
            if (! is_array($r) || array_is_list($r) && $r !== []) {
                throw ValidationException::withMessages(["registros.$i" => 'Cada registro deve ser um objeto {campo: valor}.']);
            }
            foreach ($r as $k => $v) {
                if (! in_array($k, $campos, true)) {
                    $desconhecidos[$k] = true;
                } elseif (is_array($v)) {
                    throw ValidationException::withMessages(["registros.$i.$k" => 'O valor deve ser texto, número, booleano ou nulo.']);
                }
            }
        }
        if ($desconhecidos !== []) {
            throw ValidationException::withMessages(['registros' => 'Campos desconhecidos para o tipo '.$tipo.': '.implode(', ', array_keys($desconhecidos))
                .'. Consulte GET /api/integracao/v1/tipos.']);
        }

        $id = DB::table('importacoes')->insertGetId([
            'tipo' => $tipo, 'arquivo' => mb_substr(sprintf('API %s · %s', $origem, $referencia ?: now()->format('d/m/Y H:i')), 0, 255),
            'formato' => 'json', 'origem' => $origem, 'chave_idempotencia' => $chave, 'user_id' => $sistema->id, 'status' => 'NA_FILA',
            'total_registros' => count($registros), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $caminho = "importacoes/$id.json";
        Storage::disk('local')->put($caminho, json_encode($registros, JSON_UNESCAPED_UNICODE));
        // Todos os campos do tipo entram como colunas: um campo ausente em todos os registros fica vazio, não quebra o mapeamento.
        DB::table('importacoes')->where('id', $id)->update([
            'caminho' => $caminho, 'tamanho' => Storage::disk('local')->size($caminho),
            'colunas' => json_encode($campos), 'mapeamento' => json_encode(array_combine($campos, $campos)),
        ]);
        DB::table('logs_auditoria')->insert(['user_id' => $sistema->id, 'acao' => 'integracao.receber', 'recurso' => 'importacoes', 'recurso_id' => $id,
            'ip' => request()?->ip(), 'detalhes' => json_encode(['origem' => $origem, 'tipo' => $tipo, 'registros' => count($registros)], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now()]);
        ProcessarLoteIntegracao::dispatch($id);

        return ['lote' => $this->lote($sistema, $id), 'novo' => true];
    }

    /** Valida e grava o lote (executado pela fila). */
    public function processar(int $id): void
    {
        $imp = DB::table('importacoes')->where('id', $id)->first();
        if (! $imp || $imp->status !== 'NA_FILA') {
            return;
        }
        DB::table('importacoes')->where('id', $id)->update(['status' => 'PENDENTE', 'updated_at' => now()]);
        try {
            $this->importacao->validar($id, json_decode($imp->mapeamento, true));
        } catch (ValidationException $e) {
            DB::table('importacoes')->where('id', $id)->update(['status' => 'ERRO', 'mensagem' => implode(' ', $e->validator->errors()->all()), 'updated_at' => now()]);

            return;
        }
        $this->importacao->processar($id);
    }

    /** Situação de um lote enviado pelo próprio sistema. */
    public function lote(User $sistema, int $id): array
    {
        abort_unless(DB::table('importacoes')->where('id', $id)->where('user_id', $sistema->id)->exists(), 404, 'Lote não encontrado.');
        $d = $this->importacao->detalhe($id);
        $r = $d['resumo'];

        return [
            'id' => $d['id'], 'tipo' => $d['tipo'], 'origem' => $d['origem'], 'referencia' => $d['arquivo'],
            'situacao' => $d['status'], 'concluido' => in_array($d['status'], ['CONCLUIDO', 'ERRO'], true),
            'total_registros' => $d['total_registros'], 'importados' => $d['total_importados'], 'rejeitados' => $d['total_rejeitados'],
            'duplicados' => $d['total_duplicados'], 'novos' => $r['novos'] ?? null, 'atualizacoes' => $r['atualizacoes'] ?? null,
            'erros_por_campo' => $r['erros_por_campo'] ?? (object) [], 'mensagem' => $d['mensagem'],
            'recebido_em' => $d['criada_em'], 'concluido_em' => $d['concluida_em'],
        ];
    }

    public function erros(User $sistema, int $id, int $porPagina, int $pagina)
    {
        abort_unless(DB::table('importacoes')->where('id', $id)->where('user_id', $sistema->id)->exists(), 404, 'Lote não encontrado.');

        return $this->importacao->erros($id, $porPagina, $pagina);
    }
}
