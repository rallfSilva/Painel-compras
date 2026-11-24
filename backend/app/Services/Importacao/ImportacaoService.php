<?php

namespace App\Services\Importacao;

use App\Jobs\ProcessarImportacao;
use App\Models\User;
use App\Services\AgregacaoService;
use App\Support\Rastreabilidade;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Central de importação (seção 26): recebe o arquivo, sugere o mapeamento das colunas, valida todas as
 * linhas (formato, obrigatórios, referências, duplicidades), pede confirmação, processa em fila, registra
 * o histórico e as inconsistências, permite reprocessar e recalcula as agregações ao final.
 *
 * Situações: PENDENTE (arquivo lido) → VALIDADO → NA_FILA → PROCESSANDO → CONCLUIDO | ERRO.
 */
class ImportacaoService
{
    public const TAMANHO_MAXIMO = 20 * 1024 * 1024;

    private const LIMITE_ERROS_GRAVADOS = 10000;

    public function __construct(private readonly AgregacaoService $agregacao) {}

    public function tipos(): array
    {
        return collect(RegistroImportadores::todos())->map(fn (Importador $i) => [
            'codigo' => $i->codigo(), 'titulo' => $i->titulo(), 'descricao' => $i->descricao(),
            'campos' => collect($i->campos())->map(fn ($d, $n) => [
                'nome' => $n, 'rotulo' => $d['rotulo'], 'tipo' => $d['tipo'], 'obrigatorio' => $d['obrigatorio'] ?? false, 'exemplo' => $i->exemplo()[$n] ?? '',
            ])->values()->all(),
        ])->values()->all();
    }

    private function disco()
    {
        return Storage::disk('local');
    }

    public function receber(UploadedFile $arquivo, string $tipo, User $u): array
    {
        RegistroImportadores::obter($tipo);
        $ext = strtolower($arquivo->getClientOriginalExtension());
        if (! in_array($ext, LeitorArquivo::FORMATOS, true)) {
            throw ValidationException::withMessages(['arquivo' => 'Formato não aceito. Envie XLSX, XLS, CSV ou JSON.']);
        }
        if ($arquivo->getSize() > self::TAMANHO_MAXIMO) {
            throw ValidationException::withMessages(['arquivo' => 'O arquivo passa de 20 MB. Divida-o em partes menores.']);
        }
        $id = DB::table('importacoes')->insertGetId([
            'tipo' => $tipo, 'arquivo' => mb_substr($arquivo->getClientOriginalName(), 0, 255), 'formato' => $ext, 'tamanho' => $arquivo->getSize(),
            'origem' => Rastreabilidade::ORIGEM_IMPORTACAO, 'user_id' => $u->id, 'status' => 'PENDENTE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $caminho = $this->disco()->putFileAs('importacoes', $arquivo, "$id.$ext");
        DB::table('importacoes')->where('id', $id)->update(['caminho' => $caminho]);

        return $this->ler($id);
    }

    /** Lê o arquivo, guarda as colunas e sugere o mapeamento. */
    public function ler(int $id): array
    {
        $imp = $this->registro($id);
        try {
            $dados = LeitorArquivo::ler($this->disco()->path($imp->caminho), $imp->formato);
        } catch (\Throwable $e) {
            $msg = $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Não foi possível ler o arquivo. Confira se ele não está corrompido ou protegido por senha.';
            DB::table('importacoes')->where('id', $id)->update(['status' => 'ERRO', 'mensagem' => $msg, 'updated_at' => now()]);
            throw ValidationException::withMessages(['arquivo' => $msg]);
        }
        $importador = RegistroImportadores::obter($imp->tipo);
        $mapa = $imp->mapeamento ? json_decode($imp->mapeamento, true) : RegistroImportadores::mapear($importador, $dados['colunas']);
        DB::table('importacoes')->where('id', $id)->update([
            'colunas' => json_encode($dados['colunas'], JSON_UNESCAPED_UNICODE), 'mapeamento' => json_encode($mapa, JSON_UNESCAPED_UNICODE),
            'total_registros' => count($dados['linhas']), 'updated_at' => now(),
        ]);

        return $this->detalhe($id) + ['amostra' => array_slice($dados['linhas'], 0, 10)];
    }

    /** Valida todas as linhas com o mapeamento escolhido e grava o relatório de inconsistências. */
    public function validar(int $id, array $mapeamento): array
    {
        $imp = $this->registro($id);
        if (! in_array($imp->status, ['PENDENTE', 'VALIDADO'], true)) {
            throw ValidationException::withMessages(['status' => 'Esta importação já foi processada. Use o reprocessamento.']);
        }
        $importador = RegistroImportadores::obter($imp->tipo);
        $mapeamento = $this->conferirMapeamento($importador, $mapeamento, json_decode($imp->colunas ?? '[]', true));
        DB::table('importacoes')->where('id', $id)->update(['mapeamento' => json_encode($mapeamento, JSON_UNESCAPED_UNICODE)]);

        $linhas = LeitorArquivo::ler($this->disco()->path($imp->caminho), $imp->formato)['linhas'];
        $a = $this->analisar($importador, $linhas, $mapeamento, new Contexto($id, $imp->origem, $imp->user_id));
        $this->gravarErros($id, $a['erros']);
        DB::table('importacoes')->where('id', $id)->update([
            'status' => 'VALIDADO', 'total_registros' => $a['resumo']['total'], 'total_rejeitados' => $a['resumo']['invalidos'],
            'total_duplicados' => $a['resumo']['duplicados'], 'resumo' => json_encode($a['resumo'], JSON_UNESCAPED_UNICODE), 'mensagem' => null, 'updated_at' => now(),
        ]);

        return $this->detalhe($id);
    }

    /** @return array<string, string> coluna do arquivo => campo */
    private function conferirMapeamento(Importador $imp, array $mapa, array $colunas): array
    {
        $campos = $imp->campos();
        $limpo = [];
        foreach ($mapa as $col => $campo) {
            if ($campo === null || $campo === '') {
                continue;
            }
            if (! in_array($col, $colunas, true) || ! isset($campos[$campo])) {
                throw ValidationException::withMessages(['mapeamento' => "Mapeamento inválido: coluna \"$col\" → \"$campo\"."]);
            }
            if (in_array($campo, $limpo, true)) {
                throw ValidationException::withMessages(['mapeamento' => "O campo \"{$campos[$campo]['rotulo']}\" foi ligado a mais de uma coluna."]);
            }
            $limpo[$col] = $campo;
        }
        $faltam = collect($campos)->filter(fn ($d, $n) => ($d['obrigatorio'] ?? false) && ! in_array($n, $limpo, true))->map(fn ($d) => $d['rotulo']);
        if ($faltam->isNotEmpty()) {
            throw ValidationException::withMessages(['mapeamento' => 'Ligue as colunas dos campos obrigatórios: '.$faltam->implode(', ').'.']);
        }

        return $limpo;
    }

    /**
     * Converte e valida cada linha. Linha do arquivo = posição + 2 (a linha 1 é o cabeçalho).
     * A linha de exemplo do modelo CSV, se enviada sem alterações, é ignorada (não conta no total).
     *
     * @return array{validas: array<int, array>, erros: list<array>, resumo: array}
     */
    public function analisar(Importador $imp, array $linhas, array $mapeamento, Contexto $c): array
    {
        $campos = $imp->campos();
        $vazia = array_fill_keys(array_keys($campos), null);
        $validas = [];
        $erros = [];
        $vistas = [];
        $exemplo = self::exemploConvertido($imp);
        $r = ['total' => count($linhas), 'validos' => 0, 'invalidos' => 0, 'duplicados' => 0, 'novos' => 0, 'atualizacoes' => 0, 'exemplos_ignorados' => 0, 'erros_por_campo' => [], 'amostra' => []];

        foreach ($linhas as $i => $bruta) {
            $n = $i + 2;
            $l = $vazia;
            $e = [];
            foreach ($mapeamento as $col => $campo) {
                try {
                    $l[$campo] = Valor::converter($campos[$campo]['tipo'], $bruta[$col] ?? null);
                } catch (\InvalidArgumentException $x) {
                    $e[] = [$campo, $campos[$campo]['rotulo'].': '.$x->getMessage().' ("'.mb_substr((string) $bruta[$col], 0, 60).'")'];
                }
            }
            if ($e === [] && self::ehExemplo($l, $mapeamento, $exemplo)) {
                $r['exemplos_ignorados']++;
                $r['total']--;

                continue;
            }
            foreach ($campos as $nome => $d) {
                if (($d['obrigatorio'] ?? false) && $l[$nome] === null && ! collect($e)->contains(fn ($x) => $x[0] === $nome)) {
                    $e[] = [$nome, 'Campo obrigatório vazio: '.$d['rotulo'].'.'];
                }
            }
            if ($e === []) {
                $e = $imp->validar($l, $c);
            }
            if ($e === []) {
                $k = $imp->chave($l);
                if ($k !== null && isset($vistas[$k])) {
                    $e[] = ['', "Linha duplicada: mesmo registro da linha {$vistas[$k]}. Só a primeira ocorrência é importada."];
                    $r['duplicados']++;
                } elseif ($k !== null) {
                    $vistas[$k] = $n;
                }
            }
            if ($e !== []) {
                $r['invalidos']++;
                foreach ($e as [$campo, $msg]) {
                    $erros[] = ['linha' => $n, 'campo' => $campo ?: null, 'mensagem' => $msg, 'registro' => $bruta];
                    $r['erros_por_campo'][$campo ?: 'linha'] = ($r['erros_por_campo'][$campo ?: 'linha'] ?? 0) + 1;
                }

                continue;
            }
            $r['validos']++;
            $imp->existe($l, $c) ? $r['atualizacoes']++ : $r['novos']++;
            $validas[$n] = $l;
            if (count($r['amostra']) < 20) {
                $r['amostra'][] = ['linha' => $n] + $l;
            }
        }
        arsort($r['erros_por_campo']);

        return ['validas' => $validas, 'erros' => $erros, 'resumo' => $r];
    }

    /** @return array<string, mixed> campo => valor do exemplo já convertido (como uma linha lida do arquivo) */
    private static function exemploConvertido(Importador $imp): array
    {
        $campos = $imp->campos();
        $r = [];
        foreach ($imp->exemplo() as $campo => $v) {
            try {
                $r[$campo] = isset($campos[$campo]) ? Valor::converter($campos[$campo]['tipo'], $v) : null;
            } catch (\InvalidArgumentException) {
                return [];
            }
        }

        return $r;
    }

    /** A linha é a de exemplo do modelo: todas as colunas ligadas têm o valor do exemplo (e ao menos uma está preenchida). */
    private static function ehExemplo(array $l, array $mapeamento, array $exemplo): bool
    {
        if ($exemplo === [] || $mapeamento === []) {
            return false;
        }
        $preenchida = false;
        foreach ($mapeamento as $campo) {
            $a = $l[$campo] ?? null;
            $b = $exemplo[$campo] ?? null;
            $igual = is_float($a) && is_float($b) ? abs($a - $b) < 0.005 : $a === $b;
            if (! $igual) {
                return false;
            }
            $preenchida = $preenchida || $a !== null;
        }

        return $preenchida;
    }

    private function gravarErros(int $id, array $erros): void
    {
        DB::table('importacoes_erros')->where('importacao_id', $id)->delete();
        $agora = now();
        foreach (array_chunk(array_slice($erros, 0, self::LIMITE_ERROS_GRAVADOS), 500) as $lote) {
            DB::table('importacoes_erros')->insert(array_map(fn ($e) => [
                'importacao_id' => $id, 'linha' => $e['linha'], 'campo' => $e['campo'] ? mb_substr($e['campo'], 0, 80) : null, 'mensagem' => $e['mensagem'],
                'registro' => $e['registro'] !== null ? json_encode($e['registro'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'created_at' => $agora, 'updated_at' => $agora,
            ], $lote));
        }
    }

    /** Confirma a importação validada e a coloca na fila. */
    public function confirmar(int $id, User $u): array
    {
        $imp = $this->registro($id);
        if ($imp->status !== 'VALIDADO') {
            throw ValidationException::withMessages(['status' => 'Valide o arquivo antes de confirmar.']);
        }
        $resumo = json_decode($imp->resumo ?? '{}', true);
        if (($resumo['validos'] ?? 0) === 0) {
            throw ValidationException::withMessages(['status' => 'Nenhuma linha válida para importar. Corrija o arquivo e envie de novo.']);
        }
        DB::table('importacoes')->where('id', $id)->update(['status' => 'NA_FILA', 'updated_at' => now()]);
        $this->auditar($u->id, 'importacao.confirmar', $id, ['tipo' => $imp->tipo, 'arquivo' => $imp->arquivo, 'linhas_validas' => $resumo['validos'] ?? 0]);
        ProcessarImportacao::dispatch($id);

        return $this->detalhe($id);
    }

    /** Processa a importação (executado pela fila). Linhas com erro não interrompem as demais. */
    public function processar(int $id): void
    {
        $imp = $this->registro($id);
        if (! in_array($imp->status, ['NA_FILA', 'VALIDADO'], true)) {
            return;
        }
        DB::table('importacoes')->where('id', $id)->update(['status' => 'PROCESSANDO', 'iniciada_em' => now(), 'updated_at' => now()]);
        try {
            $importador = RegistroImportadores::obter($imp->tipo);
            $c = new Contexto($id, $imp->origem, $imp->user_id);
            $linhas = LeitorArquivo::ler($this->disco()->path($imp->caminho), $imp->formato)['linhas'];
            $a = $this->analisar($importador, $linhas, json_decode($imp->mapeamento, true), $c);
            $erros = $a['erros'];
            $importados = 0;
            foreach (array_chunk($a['validas'], 500, true) as $lote) {
                DB::transaction(function () use ($lote, $importador, $c, &$erros, &$importados) {
                    foreach ($lote as $n => $l) {
                        try {
                            DB::transaction(fn () => $importador->gravar($l, $c));
                            $importados++;
                        } catch (\Throwable $e) {
                            report($e);
                            $erros[] = ['linha' => $n, 'campo' => null, 'mensagem' => 'Falha ao gravar: '.mb_substr($e->getMessage(), 0, 300), 'registro' => $l];
                        }
                    }
                });
            }
            DB::transaction(fn () => $importador->finalizar($c));
            $this->agregacao->recalcular();
            $this->gravarErros($id, $erros);
            DB::table('importacoes')->where('id', $id)->update([
                'status' => 'CONCLUIDO', 'total_registros' => $a['resumo']['total'], 'total_importados' => $importados,
                'total_rejeitados' => $a['resumo']['total'] - $importados, 'total_duplicados' => $a['resumo']['duplicados'],
                'resumo' => json_encode($a['resumo'], JSON_UNESCAPED_UNICODE), 'concluida_em' => now(), 'updated_at' => now(),
            ]);
            $this->auditar($imp->user_id, 'importacao.concluir', $id, ['tipo' => $imp->tipo, 'importados' => $importados, 'rejeitados' => $a['resumo']['total'] - $importados]);
        } catch (\Throwable $e) {
            report($e);
            DB::table('importacoes')->where('id', $id)->update(['status' => 'ERRO', 'mensagem' => mb_substr($e->getMessage(), 0, 1000), 'concluida_em' => now(), 'updated_at' => now()]);
        }
    }

    /** Reprocessamento controlado: nova importação com o mesmo arquivo e mapeamento, validada e aguardando confirmação. */
    public function reprocessar(int $id, User $u): array
    {
        $imp = $this->registro($id);
        if (! in_array($imp->status, ['CONCLUIDO', 'ERRO'], true) || ! $imp->caminho || ! $this->disco()->exists($imp->caminho)) {
            throw ValidationException::withMessages(['status' => 'Só importações concluídas ou com erro, com o arquivo guardado, podem ser reprocessadas.']);
        }
        $novo = DB::table('importacoes')->insertGetId([
            'tipo' => $imp->tipo, 'arquivo' => $imp->arquivo, 'formato' => $imp->formato, 'tamanho' => $imp->tamanho, 'origem' => $imp->origem,
            'user_id' => $u->id, 'status' => 'PENDENTE', 'mapeamento' => $imp->mapeamento, 'colunas' => $imp->colunas, 'reprocessada_de_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $caminho = "importacoes/$novo.{$imp->formato}";
        $this->disco()->copy($imp->caminho, $caminho);
        DB::table('importacoes')->where('id', $novo)->update(['caminho' => $caminho]);
        $this->auditar($u->id, 'importacao.reprocessar', $novo, ['origem_id' => $id]);
        $this->ler($novo);

        return $this->validar($novo, json_decode($imp->mapeamento ?? '{}', true));
    }

    public function excluir(int $id, User $u): void
    {
        $imp = $this->registro($id);
        if (! in_array($imp->status, ['PENDENTE', 'VALIDADO', 'ERRO'], true) || $imp->total_importados > 0) {
            throw ValidationException::withMessages(['status' => 'Importações processadas ficam no histórico e não podem ser excluídas.']);
        }
        if ($imp->caminho) {
            $this->disco()->delete($imp->caminho);
        }
        DB::table('importacoes')->where('id', $id)->delete();
        $this->auditar($u->id, 'importacao.excluir', $id, ['arquivo' => $imp->arquivo]);
    }

    private function registro(int $id): object
    {
        return DB::table('importacoes')->where('id', $id)->first() ?? abort(404, 'Importação não encontrada.');
    }

    public function detalhe(int $id): array
    {
        $i = DB::table('importacoes as i')->leftJoin('users as u', 'u.id', '=', 'i.user_id')->where('i.id', $id)->select(['i.*', 'u.name as usuario'])->first();
        abort_if(! $i, 404, 'Importação não encontrada.');
        $tipos = RegistroImportadores::todos();

        return [
            'id' => (int) $i->id, 'tipo' => $i->tipo, 'tipo_titulo' => isset($tipos[$i->tipo]) ? $tipos[$i->tipo]->titulo() : $i->tipo,
            'arquivo' => $i->arquivo, 'formato' => $i->formato, 'tamanho' => $i->tamanho !== null ? (int) $i->tamanho : null, 'origem' => $i->origem,
            'usuario' => $i->usuario, 'status' => $i->status, 'total_registros' => (int) $i->total_registros, 'total_importados' => (int) $i->total_importados,
            'total_rejeitados' => (int) $i->total_rejeitados, 'total_duplicados' => (int) $i->total_duplicados,
            'colunas' => json_decode($i->colunas ?? '[]', true), 'mapeamento' => json_decode($i->mapeamento ?? '{}', true) ?: (object) [],
            'resumo' => json_decode($i->resumo ?? 'null', true), 'mensagem' => $i->mensagem, 'reprocessada_de_id' => $i->reprocessada_de_id,
            'arquivo_disponivel' => $i->caminho && $this->disco()->exists($i->caminho),
            'criada_em' => (string) $i->created_at, 'iniciada_em' => $i->iniciada_em, 'concluida_em' => $i->concluida_em,
        ];
    }

    public function listar(array $f, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $tipos = RegistroImportadores::todos();
        $p = DB::table('importacoes as i')->leftJoin('users as u', 'u.id', '=', 'i.user_id')
            ->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('i.tipo', $t))
            ->when($f['status'] ?? [], fn ($q, $s) => $q->whereIn('i.status', $s))
            ->when($f['origem'] ?? null, fn ($q, $o) => $q->where('i.origem', $o))
            ->when($f['busca'] ?? null, fn ($q, $b) => $q->where('i.arquivo', 'like', "%$b%"))
            ->orderByDesc('i.id')
            ->select(['i.id', 'i.tipo', 'i.arquivo', 'i.formato', 'i.origem', 'i.status', 'i.total_registros', 'i.total_importados', 'i.total_rejeitados',
                'i.total_duplicados', 'i.mensagem', 'i.created_at', 'i.concluida_em', 'i.reprocessada_de_id', 'u.name as usuario'])
            ->paginate($porPagina, ['*'], 'pagina', $pagina);
        $p->getCollection()->transform(function ($r) use ($tipos) {
            $r->tipo_titulo = isset($tipos[$r->tipo]) ? $tipos[$r->tipo]->titulo() : $r->tipo;
            foreach (['total_registros', 'total_importados', 'total_rejeitados', 'total_duplicados'] as $c) {
                $r->$c = (int) $r->$c;
            }
            $r->created_at = (string) $r->created_at;

            return $r;
        });

        return $p;
    }

    public function erros(int $id, int $porPagina, int $pagina, string $busca = ''): LengthAwarePaginator
    {
        $p = DB::table('importacoes_erros')->where('importacao_id', $id)
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->where('mensagem', 'like', "%$busca%")->orWhere('campo', 'like', "%$busca%")
                ->orWhere('linha', ctype_digit($busca) ? (int) $busca : -1)))
            ->orderBy('linha')->orderBy('id')
            ->paginate($porPagina, ['id', 'linha', 'campo', 'mensagem', 'registro'], 'pagina', $pagina);
        $p->getCollection()->transform(function ($r) {
            $r->registro = $r->registro ? json_decode($r->registro, true) : null;

            return $r;
        });

        return $p;
    }

    private function auditar(?int $user, string $acao, int $id, array $detalhes): void
    {
        DB::table('logs_auditoria')->insert(['user_id' => $user, 'acao' => $acao, 'recurso' => 'importacoes', 'recurso_id' => $id, 'ip' => request()?->ip(),
            'detalhes' => json_encode($detalhes, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }
}
