<?php

namespace App\Services\Integracoes;

use App\Jobs\SincronizarIntegracao;
use App\Services\AgregacaoService;
use App\Services\Importacao\ImportacaoService;
use App\Services\Importacao\RegistroImportadores;
use App\Support\Rastreabilidade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Integrações externas (seção 26): parâmetros, teste de conexão, sincronização em fila, registro da última
 * sincronização e log de cada execução. Os registros coletados passam pela mesma validação da importação.
 */
class IntegracoesService
{
    public function __construct(private readonly ImportacaoService $importacao, private readonly AgregacaoService $agregacao) {}

    /** @return array<string, Conector> */
    public static function conectores(): array
    {
        return [
            'PNCP' => new PncpConector,
            'COMPRASGOV' => new ConectorJson('COMPRASGOV', Rastreabilidade::ORIGEM_COMPRASGOV,
                'Dados abertos de compras federais (Compras.gov.br). Configure a consulta e o mapeamento conforme o módulo da API escolhido.',
                'https://dadosabertos.compras.gov.br/swagger-ui/index.html', ['caminho_dados' => 'resultado', 'importador' => 'PROCESSOS']),
            'FINANCEIRO_RR' => new ConectorJson('FINANCEIRO_RR', Rastreabilidade::ORIGEM_SISTEMA_ESTADUAL,
                'Empenho, liquidação e pagamento do sistema de execução orçamentária e financeira do Estado. Enquanto não houver API, use a importação de arquivo "Execução financeira".',
                '', ['caminho_dados' => '', 'importador' => 'EXECUCAO_FINANCEIRA',
                    'mapeamento' => json_encode(['tipo' => 'fase', 'documento' => 'numero', 'data' => 'data', 'valor' => 'valor', 'contrato' => 'contrato', 'processo' => 'processo'], JSON_PRETTY_PRINT)]),
        ];
    }

    public static function conector(string $codigo): Conector
    {
        return self::conectores()[$codigo] ?? abort(404, 'Integração desconhecida.');
    }

    private function registro(string $codigo): object
    {
        return DB::table('integracoes')->where('codigo', $codigo)->first() ?? abort(404, 'Integração não cadastrada.');
    }

    /** Parâmetros salvos sobre os padrões do conector. */
    public function parametros(string $codigo): array
    {
        $salvos = json_decode($this->registro($codigo)->parametros ?? '{}', true) ?: [];

        return collect(self::conector($codigo)->parametros())->map(fn ($d, $n) => $salvos[$n] ?? $d['padrao'])->all();
    }

    public function listar(): array
    {
        return collect(self::conectores())->map(function (Conector $c, $codigo) {
            $r = DB::table('integracoes')->where('codigo', $codigo)->first();
            if (! $r) {
                return null;
            }
            $logs = DB::table('logs_sincronizacao as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')->where('l.integracao_id', $r->id)
                ->orderByDesc('l.id')->limit(10)->get(['l.id', 'l.status', 'l.registros_recebidos', 'l.registros_gravados', 'l.mensagem', 'l.iniciado_em', 'l.finalizado_em', 'l.importacao_id', 'u.name as usuario']);

            return [
                'codigo' => $codigo, 'nome' => $r->nome, 'descricao' => $c->descricao(), 'documentacao' => $c->documentacao(), 'ativa' => (bool) $r->ativa,
                'ultima_sincronizacao_em' => $r->ultima_sincronizacao_em, 'token_configurado' => (bool) config("integracoes.tokens.$codigo"),
                'definicao' => collect($c->parametros())->map(fn ($d, $n) => ['nome' => $n] + $d)->values()->all(),
                'parametros' => $this->parametros($codigo), 'logs' => $logs->all(),
                'em_execucao' => $logs->contains(fn ($l) => in_array($l->status, ['NA_FILA', 'EXECUTANDO'], true)),
            ];
        })->filter()->values()->all();
    }

    public function salvar(string $codigo, array $entrada, int $userId): void
    {
        $c = self::conector($codigo);
        $def = $c->parametros();
        $param = [];
        foreach ($entrada['parametros'] ?? [] as $n => $v) {
            if (! isset($def[$n])) {
                continue;
            }
            $param[$n] = match ($def[$n]['tipo']) {
                'numero' => max(1, min(3650, (int) $v)),
                'booleano' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                'json' => $this->json($n, $v),
                default => mb_substr(trim((string) $v), 0, 1000),
            };
        }
        if (isset($param['importador']) && ! array_key_exists($param['importador'], RegistroImportadores::todos())) {
            throw ValidationException::withMessages(['importador' => 'Tipo de registro inválido.']);
        }
        if (isset($param['url']) && $param['url'] !== '' && ! preg_match('#^https://#i', $param['url'])) {
            throw ValidationException::withMessages(['url' => 'Use uma URL https.']);
        }
        $r = $this->registro($codigo);
        $antes = ['ativa' => (bool) $r->ativa, 'parametros' => json_decode($r->parametros ?? '{}', true)];
        DB::table('integracoes')->where('id', $r->id)->update(['ativa' => (bool) ($entrada['ativa'] ?? $r->ativa),
            'parametros' => json_encode($param + (json_decode($r->parametros ?? '{}', true) ?: []), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        DB::table('logs_auditoria')->insert(['user_id' => $userId, 'acao' => 'integracoes.configurar', 'recurso' => 'integracoes', 'recurso_id' => $r->id,
            'ip' => request()?->ip(), 'detalhes' => json_encode(['codigo' => $codigo, 'antes' => $antes, 'depois' => ['ativa' => (bool) ($entrada['ativa'] ?? $r->ativa), 'parametros' => $param]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function json(string $campo, mixed $v): string
    {
        $d = is_array($v) ? $v : json_decode((string) $v, true);
        if (! is_array($d)) {
            throw ValidationException::withMessages([$campo => 'JSON inválido.']);
        }

        return json_encode($d, JSON_UNESCAPED_UNICODE);
    }

    public function testar(string $codigo): array
    {
        return self::conector($codigo)->testar($this->parametros($codigo));
    }

    /** Coloca a sincronização na fila e devolve o id do log. */
    public function agendar(string $codigo, ?int $userId, ?string $de = null, ?string $ate = null): int
    {
        $r = $this->registro($codigo);
        if (DB::table('logs_sincronizacao')->where('integracao_id', $r->id)->whereIn('status', ['NA_FILA', 'EXECUTANDO'])->where('iniciado_em', '>', now()->subHours(2))->exists()) {
            throw ValidationException::withMessages(['status' => 'Já existe uma sincronização desta integração em andamento.']);
        }
        $log = DB::table('logs_sincronizacao')->insertGetId(['integracao_id' => $r->id, 'user_id' => $userId, 'status' => 'NA_FILA', 'iniciado_em' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        SincronizarIntegracao::dispatch($codigo, $log, $de, $ate);

        return $log;
    }

    /** Executa a sincronização (fila): coleta, grava pelos importadores e registra o resultado. */
    public function sincronizar(string $codigo, int $logId, ?string $de = null, ?string $ate = null): void
    {
        $r = $this->registro($codigo);
        $c = self::conector($codigo);
        $p = $this->parametros($codigo);
        $ate = $ate ? CarbonImmutable::parse($ate) : CarbonImmutable::today();
        $de = $de ? CarbonImmutable::parse($de) : $ate->subDays((int) ($p['dias'] ?? 30));
        $log = DB::table('logs_sincronizacao')->where('id', $logId);
        $log->update(['status' => 'EXECUTANDO', 'updated_at' => now()]);
        $userId = DB::table('logs_sincronizacao')->where('id', $logId)->value('user_id');
        try {
            $recebidos = 0;
            $gravados = 0;
            $ignorados = 0;
            $ultima = null;
            foreach ($c->coletar($p, $de, $ate) as $lote) {
                $recebidos += count($lote['linhas']);
                $ignorados += $lote['ignorados'] ?? 0;
                $ultima = $this->importarLote($c, $lote, $userId, $de, $ate);
                $gravados += (int) DB::table('importacoes')->where('id', $ultima)->value('total_importados');
            }
            DB::table('integracoes')->where('id', $r->id)->update(['ultima_sincronizacao_em' => now(), 'updated_at' => now()]);
            $msg = sprintf('Período %s a %s. %d registro(s) recebido(s), %d gravado(s).', $de->format('d/m/Y'), $ate->format('d/m/Y'), $recebidos, $gravados)
                .($ignorados ? " $ignorados registro(s) de outros órgãos ignorado(s)." : '')
                .($recebidos > $gravados ? ' Veja as inconsistências no histórico de importações.' : '');
            $log->update(['status' => 'CONCLUIDO', 'registros_recebidos' => $recebidos, 'registros_gravados' => $gravados, 'mensagem' => $msg,
                'importacao_id' => $ultima, 'finalizado_em' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
            $log->update(['status' => 'ERRO', 'mensagem' => mb_substr($e->getMessage(), 0, 1000), 'finalizado_em' => now(), 'updated_at' => now()]);
        }
    }

    /** Grava um lote coletado como uma importação JSON (fica no histórico, com as inconsistências). */
    private function importarLote(Conector $c, array $lote, ?int $userId, CarbonImmutable $de, CarbonImmutable $ate): int
    {
        $campos = array_keys(RegistroImportadores::obter($lote['tipo'])->campos());
        $id = DB::table('importacoes')->insertGetId([
            'tipo' => $lote['tipo'], 'arquivo' => sprintf('%s · %s a %s', $lote['descricao'], $de->format('d/m/Y'), $ate->format('d/m/Y')),
            'formato' => 'json', 'origem' => $c->origem(), 'user_id' => $userId, 'status' => 'PENDENTE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $caminho = "importacoes/$id.json";
        Storage::disk('local')->put($caminho, json_encode($lote['linhas'], JSON_UNESCAPED_UNICODE));
        $mapa = array_combine($campos, $campos);
        DB::table('importacoes')->where('id', $id)->update(['caminho' => $caminho, 'tamanho' => Storage::disk('local')->size($caminho),
            'colunas' => json_encode($campos), 'mapeamento' => json_encode($mapa)]);
        $this->importacao->validar($id, $mapa);
        $this->importacao->processar($id);

        return $id;
    }
}
