<?php

namespace App\Services\Planejamento;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Services\Inteligencia\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * MÓDULO PLANO DE CONTRATAÇÕES ANUAL — PCA (seção 20).
 *
 * - Planejado: Σ valor estimado das demandas, exceto as excluídas do plano.
 * - Contratado: Σ valor contratado dos itens dos processos vinculados à demanda (pca_execucao).
 * - % executado = contratado / planejado × 100.
 * - Atrasada: data pretendida já passou e a demanda não foi concluída nem cancelada.
 * - Filtros globais aplicados: órgão/unidade (acesso e seleção), grupo/classe e período (exercício).
 */
class PcaService
{
    public const EXECUCAO = ['NAO_INICIADA' => 'Não iniciada', 'EM_ANDAMENTO' => 'Em andamento', 'CONCLUIDA' => 'Concluída', 'CANCELADA' => 'Cancelada'];

    public const CATEGORIAS = ['MATERIAL' => 'Material', 'SERVICO' => 'Serviço', 'OBRA' => 'Obra', 'TIC' => 'Tecnologia da informação'];

    private const ORDENACAO = ['exercicio' => 'pca.exercicio', 'numero_demanda' => 'd.numero_demanda', 'valor_estimado' => 'd.valor_estimado',
        'data_pretendida' => 'd.data_pretendida', 'contratado' => 'contratado', 'orgao' => 'o.sigla'];

    public function __construct(private readonly FiltroService $filtros) {}

    /** Valor contratado da demanda: itens dos processos vinculados. */
    private static function contratadoSql(): string
    {
        return '(SELECT COALESCE(SUM(pi.valor_total_contratado), 0) FROM pca_execucao pe JOIN processos_itens pi ON pi.processo_id = pe.processo_id WHERE pe.demanda_id = d.id)';
    }

    public function base(FiltrosAplicados $f): Builder
    {
        $q = DB::table('pca_demandas as d')
            ->join('pca', 'pca.id', '=', 'd.pca_id')
            ->join('orgaos as o', 'o.id', '=', 'pca.orgao_id')
            ->leftJoin('unidades_compradoras as ur', 'ur.id', '=', 'd.unidade_requisitante_id')
            ->leftJoin('classes_materiais as cm', 'cm.id', '=', 'd.classe_id')
            ->leftJoin('grupos_materiais as gm', 'gm.id', '=', DB::raw('COALESCE(d.grupo_id, cm.grupo_id)'));

        if ($f->orgaosPermitidos !== null) {
            $q->whereIn('o.id', $f->orgaosPermitidos ?: [0]);
        }
        if ($i = $f->intervalo()) {
            $q->whereBetween('pca.exercicio', [$i[0]->year, $i[1]->year]);
        }
        $anos = collect($f->valores('tempo'))->map(fn ($c) => preg_match('/^\w:(\d{4})/', $c, $m) ? (int) $m[1] : null)->filter()->unique()->values();
        if ($anos->isNotEmpty()) {
            $q->whereIn('pca.exercicio', $anos->all());
        }
        $this->porChave($q, $f->valores('unidade'), ['us' => 'o.unidade_superior_id', 'o' => 'o.id', 'u' => 'd.unidade_requisitante_id']);
        $this->porChave($q, $f->valores('classe'), ['g' => 'gm.id', 'c' => 'd.classe_id']);

        return $q;
    }

    /** @param array<string, string> $mapa prefixo => coluna; chaves diferentes do mesmo filtro somam (OU). */
    private function porChave(Builder $q, array $chaves, array $mapa): void
    {
        $grupos = [];
        foreach ($chaves as $c) {
            [$p, $v] = array_pad(explode(':', $c, 2), 2, null);
            if (isset($mapa[$p]) && ctype_digit((string) $v)) {
                $grupos[$p][] = (int) $v;
            }
        }
        if ($grupos) {
            $q->where(function (Builder $w) use ($grupos, $mapa) {
                foreach ($grupos as $p => $ids) {
                    $w->orWhereIn($mapa[$p], $ids);
                }
            });
        }
    }

    public function painel(FiltrosAplicados $f): array
    {
        $hoje = $f->hoje()->toDateString();
        $c = self::contratadoSql();
        $ativas = fn () => $this->base($f)->where('d.situacao_planejamento', '!=', 'EXCLUIDA');

        $r = $ativas()->selectRaw("SUM(d.valor_estimado) AS planejado, SUM($c) AS contratado, COUNT(*) AS demandas,
            SUM(CASE WHEN d.situacao_execucao = 'NAO_INICIADA' THEN 1 ELSE 0 END) AS nao_iniciadas,
            SUM(CASE WHEN d.situacao_execucao = 'EM_ANDAMENTO' THEN 1 ELSE 0 END) AS em_andamento,
            SUM(CASE WHEN d.situacao_execucao = 'CONCLUIDA' THEN 1 ELSE 0 END) AS concluidas,
            SUM(CASE WHEN d.situacao_execucao = 'CANCELADA' THEN 1 ELSE 0 END) AS canceladas,
            SUM(CASE WHEN d.data_pretendida < ? AND d.situacao_execucao IN ('NAO_INICIADA', 'EM_ANDAMENTO') THEN 1 ELSE 0 END) AS atrasadas", [$hoje])->first();

        $planejado = (float) $r->planejado;
        $contratado = (float) $r->contratado;

        return [
            'indicadores' => [
                'planejado' => Sql::valor($planejado), 'contratado' => Sql::valor($contratado), 'percentual_executado' => Sql::pct($contratado, $planejado),
                'diferenca' => Sql::valor($planejado - $contratado), 'demandas' => (int) $r->demandas,
                'nao_iniciadas' => (int) $r->nao_iniciadas, 'em_andamento' => (int) $r->em_andamento, 'concluidas' => (int) $r->concluidas,
                'canceladas' => (int) $r->canceladas, 'atrasadas' => (int) $r->atrasadas,
            ],
            'por_orgao' => $this->comparar($ativas(), 'o.id', 'COALESCE(o.sigla, o.nome)', 'unidade', 'o'),
            'por_categoria' => collect($this->comparar($ativas(), 'd.categoria', 'd.categoria', null, null))
                ->map(fn ($l) => ['nome' => self::CATEGORIAS[$l['nome']] ?? $l['nome']] + $l)->all(),
            'por_classe' => $this->comparar($ativas(), 'd.classe_id', 'cm.nome', 'classe', 'c', 20),
            'por_exercicio' => $ativas()->groupBy('pca.exercicio')->orderBy('pca.exercicio')
                ->selectRaw("pca.exercicio AS ano, SUM(d.valor_estimado) AS planejado, SUM($c) AS contratado")->get()
                ->map(fn ($x) => ['ano' => (int) $x->ano, 'planejado' => Sql::valor($x->planejado), 'contratado' => Sql::valor($x->contratado),
                    'percentual' => Sql::pct($x->contratado, $x->planejado)])->all(),
            'por_situacao' => collect(self::EXECUCAO)->map(fn ($n, $k) => ['situacao' => $k, 'nome' => $n,
                'quantidade' => (int) ($r->{['NAO_INICIADA' => 'nao_iniciadas', 'EM_ANDAMENTO' => 'em_andamento', 'CONCLUIDA' => 'concluidas', 'CANCELADA' => 'canceladas'][$k]})])->values()->all(),
            'fora_do_pca' => $this->foraDoPca($f),
        ];
    }

    private function comparar(Builder $q, string $col, string $nome, ?string $filtro, ?string $prefixo, int $limite = 30): array
    {
        $c = self::contratadoSql();

        return $q->groupBy($col)
            ->selectRaw("$col AS id, MAX($nome) AS nome, SUM(d.valor_estimado) AS planejado, SUM($c) AS contratado, COUNT(*) AS demandas")
            ->orderByDesc('planejado')->limit($limite)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'nome' => $r->nome ?? 'Não informado', 'planejado' => Sql::valor($r->planejado), 'contratado' => Sql::valor($r->contratado),
                'percentual' => Sql::pct($r->contratado, $r->planejado), 'diferenca' => Sql::valor((float) $r->planejado - (float) $r->contratado),
                'demandas' => (int) $r->demandas,
                'filtro' => $filtro && $r->id !== null ? $filtro : null, 'chave' => $prefixo && $r->id !== null ? "$prefixo:$r->id" : null,
            ])->all();
    }

    /** Contratações com os filtros globais que não estão vinculadas a nenhuma demanda do PCA. */
    private function foraDoPca(FiltrosAplicados $f): array
    {
        $r = $this->filtros->baseItens($f)
            ->whereNotExists(fn ($w) => $w->from('pca_execucao as pe')->whereColumn('pe.processo_id', 'p.id'))
            ->selectRaw('COUNT(DISTINCT p.id) AS processos, SUM(pi.valor_total_contratado) AS contratado')->first();
        $total = (float) $this->filtros->baseItens($f)->sum('pi.valor_total_contratado');

        return ['processos' => (int) $r->processos, 'contratado' => Sql::valor($r->contratado ?? 0), 'percentual' => Sql::pct($r->contratado, $total)];
    }

    /** @param array{busca: string, situacao: list<string>} $local */
    public function listar(FiltrosAplicados $f, array $local, string $ordem, string $direcao, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $hoje = $f->hoje()->toDateString();
        $q = $this->base($f);
        if ($sit = array_values(array_intersect($local['situacao'], array_keys(self::EXECUCAO)))) {
            $q->whereIn('d.situacao_execucao', $sit);
        }
        if ($local['busca'] !== '') {
            $t = '%'.mb_strtolower($local['busca']).'%';
            $q->where(fn ($w) => $w->whereRaw('LOWER(d.objeto) LIKE ?', [$t])->orWhereRaw('LOWER(d.numero_demanda) LIKE ?', [$t])
                ->orWhereRaw('LOWER(d.codigo_contratacao) LIKE ?', [$t])->orWhereRaw('LOWER(d.processo_sei) LIKE ?', [$t]));
        }
        $p = $q->selectRaw('d.id, pca.exercicio, o.sigla AS orgao, ur.nome AS unidade_requisitante, d.numero_demanda, d.codigo_contratacao, d.objeto,
                d.descricao_necessidade, d.categoria, gm.nome AS grupo, cm.nome AS classe, d.quantidade_estimada, d.valor_estimado, d.data_pretendida,
                d.prioridade, d.situacao_planejamento, d.responsavel, d.processo_sei, d.situacao_execucao, '.self::contratadoSql().' AS contratado,
                (SELECT MIN(p2.numero_publicacao) FROM pca_execucao pe2 JOIN processos p2 ON p2.id = pe2.processo_id WHERE pe2.demanda_id = d.id) AS processo_relacionado,
                (SELECT MIN(pe3.processo_id) FROM pca_execucao pe3 WHERE pe3.demanda_id = d.id) AS processo_id')
            ->orderBy(self::ORDENACAO[$ordem] ?? 'd.data_pretendida', $direcao)->orderBy('d.id')
            ->paginate($porPagina, ['*'], 'pagina', $pagina);
        $p->getCollection()->transform(function ($r) use ($hoje) {
            $r->valor_estimado = (float) $r->valor_estimado;
            $r->contratado = (float) $r->contratado;
            $r->quantidade_estimada = $r->quantidade_estimada !== null ? (float) $r->quantidade_estimada : null;
            $r->percentual_executado = Sql::pct($r->contratado, $r->valor_estimado);
            $r->atrasada = $r->data_pretendida !== null && $r->data_pretendida < $hoje && in_array($r->situacao_execucao, ['NAO_INICIADA', 'EM_ANDAMENTO'], true);
            $r->categoria_nome = self::CATEGORIAS[$r->categoria] ?? $r->categoria;
            $r->processo_id = $r->processo_id !== null ? (int) $r->processo_id : null;

            return $r;
        });

        return $p;
    }
}
