<?php

namespace App\Services\Inteligencia;

use App\Services\Compras\ComprasTotaisService;
use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * MÓDULO LICITAÇÕES PARA REGISTRO DE PREÇOS (seção 14): formação das atas.
 *
 * A grandeza principal é o valor adjudicado. A economia RP compara estimado e adjudicado nos itens que têm
 * os dois valores. Os gráficos "RP Sim x Não" ignoram o filtro global de RP, porque comparam os dois grupos.
 */
class LicitacoesRpService
{
    public function __construct(
        private readonly FiltroService $filtros,
        private readonly ComprasTotaisService $compras,
        private readonly Configuracoes $config,
    ) {}

    private static function rp(FiltrosAplicados $f): FiltrosAplicados
    {
        return $f->com('rp', ['sim']);
    }

    /** Atas formadas pelos processos de RP filtrados (exceto canceladas). */
    public function atas(FiltrosAplicados $f): Builder
    {
        return DB::table('atas_registro_precos as a')
            ->where('a.situacao', '!=', 'CANCELADA')
            ->whereIn('a.processo_id', $this->filtros->idsProcessos(self::rp($f)));
    }

    public function painel(FiltrosAplicados $f): array
    {
        $rp = self::rp($f);
        $res = Sql::resultado();
        $comp = "$res AND pi.valor_total_estimado IS NOT NULL AND pi.valor_total_adjudicado IS NOT NULL";
        $r = $this->filtros->baseItens($rp)->selectRaw("
            SUM(CASE WHEN $res THEN pi.valor_total_estimado END) AS estimado,
            SUM(pi.valor_total_adjudicado) AS adjudicado,
            SUM(CASE WHEN $comp THEN pi.valor_total_estimado - pi.valor_total_adjudicado END) AS economia,
            SUM(CASE WHEN $comp THEN pi.valor_total_estimado END) AS estimado_comparavel,
            COUNT(DISTINCT CASE WHEN $res THEN pi.item_catalogo_id END) AS itens_catalogo,
            COUNT(DISTINCT p.id) AS processos,
            COUNT(DISTINCT pi.fornecedor_id) AS fornecedores
        ")->first();

        $hoje = $f->hoje()->toDateString();
        $limite = $f->hoje()->addDays($this->config->int('atas.dias_alerta_vencimento', 90))->toDateString();
        $a = $this->atas($f)->selectRaw("
            COUNT(DISTINCT a.orgao_gerenciador_id) AS gestores,
            SUM(CASE WHEN a.situacao = 'VIGENTE' AND a.vigencia_fim >= ? THEN 1 ELSE 0 END) AS vigentes,
            SUM(CASE WHEN a.situacao = 'EXPIRADA' OR a.vigencia_fim < ? THEN 1 ELSE 0 END) AS expiradas,
            SUM(CASE WHEN a.situacao = 'VIGENTE' AND a.vigencia_fim >= ? AND a.vigencia_fim <= ? THEN 1 ELSE 0 END) AS vencendo
        ", [$hoje, $hoje, $hoje, $limite])->first();
        $itensRegistrados = DB::table('atas_itens')->whereIn('ata_id', $this->atas($f)->select('a.id'))->count();

        return [
            'indicadores' => [
                'total_estimado' => Sql::valor($r->estimado),
                'total_adjudicado' => Sql::valor($r->adjudicado),
                'economia' => Sql::valor($r->economia),
                'percentual_economia' => Sql::pct($r->economia, $r->estimado_comparavel),
                'itens_catalogo' => (int) $r->itens_catalogo,
                'itens_registrados' => $itensRegistrados,
                'processos' => (int) $r->processos,
                'gestores' => (int) $a->gestores,
                'fornecedores' => (int) $r->fornecedores,
                'atas_vigentes' => (int) $a->vigentes,
                'atas_expiradas' => (int) $a->expiradas,
                'atas_vencendo' => (int) $a->vencendo,
            ],
            'dias_alerta_vencimento' => $this->config->int('atas.dias_alerta_vencimento', 90),
            'graficos' => [
                'porte_por_ano' => $this->portePorAno($rp),
                'pregao_rp' => $this->simNaoPorAno($f, fn (Builder $q) => $q->where('s.codigo', 'PREGAO')),
                'consumo_rp' => $this->simNaoPorAno($f, fn (Builder $q) => $q->join('classificacoes_natureza as cnr', 'cnr.id', '=', 'p.classificacao_natureza_id')->where('cnr.codigo', 'CONSUMO')),
                'economia_pregao_rp' => $this->economiaPregao($f),
                'por_uf' => $this->compras->agrupar($rp, 'ef.uf', 'ef.uf', 'fornecedor_local', 'uf', 12, null, 'pi.valor_total_adjudicado'),
                'por_ano' => $this->porAno($rp),
                'por_grupo_classe' => $this->treemap($rp),
                'eletronico_presencial' => $this->eletronicoPresencial($rp),
            ],
        ];
    }

    /** Gráfico 1: % do adjudicado RP por grupo de porte (MPE / Outro / N/I), por ano (barras 100%). */
    private function portePorAno(FiltrosAplicados $rp): array
    {
        $g = "COALESCE(pe.grupo, 'NI')";

        return $this->filtros->baseItens($rp)->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')
            ->whereNotNull('pi.valor_total_adjudicado')
            ->groupBy('p.ano')->orderBy('p.ano')
            ->selectRaw("p.ano, SUM(pi.valor_total_adjudicado) AS total,
                SUM(CASE WHEN $g = 'MPE' THEN pi.valor_total_adjudicado END) AS mpe,
                SUM(CASE WHEN $g = 'OUTRO' THEN pi.valor_total_adjudicado END) AS outro,
                SUM(CASE WHEN $g = 'NI' THEN pi.valor_total_adjudicado END) AS ni")
            ->get()
            ->map(fn ($r) => [
                'ano' => (int) $r->ano, 'total' => Sql::valor($r->total),
                'mpe' => Sql::pct($r->mpe, $r->total) ?? 0, 'outro' => Sql::pct($r->outro, $r->total) ?? 0, 'ni' => Sql::pct($r->ni, $r->total) ?? 0,
                'valor_mpe' => Sql::valor($r->mpe ?? 0), 'valor_outro' => Sql::valor($r->outro ?? 0), 'valor_ni' => Sql::valor($r->ni ?? 0),
            ])->all();
    }

    /** Gráficos 2 e 3: adjudicado com RP x sem RP, por ano, num recorte (pregão, material de consumo). */
    private function simNaoPorAno(FiltrosAplicados $f, \Closure $recorte): array
    {
        $q = $this->filtros->baseItens($f->com('rp', []))->whereNotNull('pi.valor_total_adjudicado');
        $recorte($q);

        return $q->groupBy('p.ano')->orderBy('p.ano')
            ->selectRaw('p.ano, SUM(CASE WHEN p.registro_precos = 1 THEN pi.valor_total_adjudicado END) AS sim,
                SUM(CASE WHEN p.registro_precos = 0 THEN pi.valor_total_adjudicado END) AS nao')
            ->get()
            ->map(function ($r) {
                $t = (float) $r->sim + (float) $r->nao;

                return [
                    'ano' => (int) $r->ano, 'valor_sim' => Sql::valor($r->sim ?? 0), 'valor_nao' => Sql::valor($r->nao ?? 0),
                    'sim' => Sql::pct($r->sim, $t) ?? 0, 'nao' => Sql::pct($r->nao, $t) ?? 0,
                ];
            })->all();
    }

    /** Gráfico 4: % de economia (estimado x adjudicado) em pregões, com e sem RP, por ano. */
    private function economiaPregao(FiltrosAplicados $f): array
    {
        $comp = Sql::resultado().' AND pi.valor_total_estimado IS NOT NULL AND pi.valor_total_adjudicado IS NOT NULL';

        return $this->filtros->baseItens($f->com('rp', []))->where('s.codigo', 'PREGAO')
            ->groupBy('p.ano')->orderBy('p.ano')
            ->selectRaw("p.ano,
                SUM(CASE WHEN $comp AND p.registro_precos = 1 THEN pi.valor_total_estimado - pi.valor_total_adjudicado END) AS eco_sim,
                SUM(CASE WHEN $comp AND p.registro_precos = 1 THEN pi.valor_total_estimado END) AS est_sim,
                SUM(CASE WHEN $comp AND p.registro_precos = 0 THEN pi.valor_total_estimado - pi.valor_total_adjudicado END) AS eco_nao,
                SUM(CASE WHEN $comp AND p.registro_precos = 0 THEN pi.valor_total_estimado END) AS est_nao")
            ->get()
            ->map(fn ($r) => ['ano' => (int) $r->ano, 'sim' => Sql::pct($r->eco_sim, $r->est_sim), 'nao' => Sql::pct($r->eco_nao, $r->est_nao)])
            ->all();
    }

    /** Gráfico 6: valor adjudicado RP e nº de itens adjudicados por ano (exibidos em gráficos separados, sem eixo duplo). */
    private function porAno(FiltrosAplicados $rp): array
    {
        return $this->filtros->baseItens($rp)->whereRaw(Sql::resultado())
            ->groupBy('p.ano')->orderBy('p.ano')
            ->selectRaw('p.ano, SUM(pi.valor_total_adjudicado) AS valor, COUNT(*) AS itens')
            ->get()->map(fn ($r) => ['ano' => (int) $r->ano, 'valor' => Sql::valor($r->valor), 'itens' => (int) $r->itens])->all();
    }

    /** Gráfico 7: adjudicado RP por grupo e classe de material (treemap). */
    private function treemap(FiltrosAplicados $rp): array
    {
        $linhas = $this->filtros->baseItens($rp)->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')
            ->whereNotNull('pi.valor_total_adjudicado')
            ->groupBy('gm.id', 'cm.id')
            ->selectRaw('gm.id AS grupo_id, MAX(gm.nome) AS grupo, cm.id AS classe_id, MAX(cm.nome) AS classe, SUM(pi.valor_total_adjudicado) AS valor')
            ->get();
        $total = (float) $linhas->sum('valor');

        return $linhas->groupBy('grupo_id')->map(fn ($classes) => [
            'id' => $classes[0]->grupo_id !== null ? (int) $classes[0]->grupo_id : null,
            'nome' => $classes[0]->grupo ?? 'Sem grupo',
            'valor' => Sql::valor($classes->sum('valor')),
            'percentual' => Sql::pct($classes->sum('valor'), $total),
            'filtro' => $classes[0]->grupo_id !== null ? 'classe' : null,
            'chave' => $classes[0]->grupo_id !== null ? 'g:'.$classes[0]->grupo_id : null,
            'classes' => $classes->sortByDesc('valor')->map(fn ($c) => [
                'id' => $c->classe_id !== null ? (int) $c->classe_id : null, 'nome' => $c->classe ?? 'Sem classe',
                'valor' => Sql::valor($c->valor), 'percentual' => Sql::pct($c->valor, $total),
                'filtro' => $c->classe_id !== null ? 'classe' : null, 'chave' => $c->classe_id !== null ? 'c:'.$c->classe_id : null,
            ])->values()->all(),
        ])->sortByDesc('valor')->values()->all();
    }

    /** Gráfico 8: processos RP eletrônicos x presenciais, no total e por unidade gerenciadora. */
    private function eletronicoPresencial(FiltrosAplicados $rp): array
    {
        $porUnidade = $this->filtros->baseItens($rp)
            ->groupBy('uc.id')
            ->selectRaw('uc.id, MAX(uc.nome) AS nome, MAX(o.sigla) AS orgao,
                COUNT(DISTINCT CASE WHEN p.eletronico = 1 THEN p.id END) AS eletronicos,
                COUNT(DISTINCT CASE WHEN p.eletronico = 0 THEN p.id END) AS presenciais')
            ->get();

        return [
            'total' => [
                ['grupo' => 'ELETRONICO', 'nome' => 'Eletrônicos', 'processos' => (int) $porUnidade->sum('eletronicos')],
                ['grupo' => 'PRESENCIAL', 'nome' => 'Presenciais', 'processos' => (int) $porUnidade->sum('presenciais')],
            ],
            'por_unidade' => $porUnidade->map(fn ($r) => [
                'id' => (int) $r->id, 'nome' => $r->nome, 'orgao' => $r->orgao,
                'eletronicos' => (int) $r->eletronicos, 'presenciais' => (int) $r->presenciais,
                'percentual_eletronicos' => Sql::pct($r->eletronicos, $r->eletronicos + $r->presenciais),
                'filtro' => 'unidade', 'chave' => "u:$r->id",
            ])->sortByDesc(fn ($r) => $r['eletronicos'] + $r['presenciais'])->values()->all(),
        ];
    }

    public const ORDENACAO = [
        'data_publicacao' => 'p.data_publicacao', 'valor_estimado' => 'valor_estimado', 'valor_adjudicado' => 'valor_adjudicado',
        'valor_homologado' => 'valor_homologado', 'itens' => 'itens', 'vigencia_ata' => 'vigencia_ata', 'numero_publicacao' => 'p.numero_publicacao',
    ];

    /** Lista de licitações para RP com os campos da seção 14.2. */
    public function processos(FiltrosAplicados $f, array $local, string $ordem, string $direcao, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $res = Sql::resultado();
        $ids = $this->filtros->idsProcessos(self::rp($f));
        $q = DB::table('processos as p')
            ->join('unidades_compradoras as uc', 'uc.id', '=', 'p.unidade_compradora_id')
            ->join('orgaos as o', 'o.id', '=', 'uc.orgao_id')
            ->join('sistematicas_aquisicao as s', 's.id', '=', 'p.sistematica_id')
            ->leftJoin('formas_aquisicao as fa', 'fa.id', '=', 'p.forma_aquisicao_id')
            ->leftJoin('processos_itens as pi', 'pi.processo_id', '=', 'p.id')
            ->whereIn('p.id', $ids)
            ->when($local['busca'] !== '', function (Builder $q) use ($local) {
                $t = '%'.$local['busca'].'%';
                $q->where(fn ($w) => $w->where('p.objeto', 'like', $t)->orWhere('p.numero_publicacao', 'like', $t)
                    ->orWhere('p.numero_processo_sei', 'like', $t)->orWhere('p.numero_licitacao', 'like', $t));
            })
            ->when($local['situacao'], fn ($q) => $q->whereIn('p.situacao', $local['situacao']))
            ->groupBy('p.id')
            ->selectRaw("p.id, MAX(p.numero_processo_sei) AS numero_processo, MAX(p.numero_licitacao) AS numero_licitacao,
                MAX(p.numero_publicacao) AS numero_publicacao, MAX(uc.nome) AS orgao_gerenciador, MAX(o.sigla) AS orgao,
                MAX(p.objeto) AS objeto, MAX(COALESCE(fa.nome, s.nome)) AS modalidade, MAX(p.data_publicacao) AS data_publicacao,
                MAX(p.data_sessao) AS data_sessao, COUNT(pi.id) AS itens,
                SUM(CASE WHEN $res THEN pi.valor_total_estimado END) AS valor_estimado,
                SUM(pi.valor_total_adjudicado) AS valor_adjudicado, SUM(pi.valor_total_homologado) AS valor_homologado,
                COUNT(DISTINCT pi.fornecedor_id) AS fornecedores_vencedores, MAX(p.situacao) AS situacao,
                (SELECT MAX(a.vigencia_fim) FROM atas_registro_precos a WHERE a.processo_id = p.id AND a.situacao <> 'CANCELADA') AS vigencia_ata,
                (SELECT MIN(a.numero) FROM atas_registro_precos a WHERE a.processo_id = p.id AND a.situacao <> 'CANCELADA') AS numero_ata,
                (SELECT COUNT(*) FROM atas_registro_precos a WHERE a.processo_id = p.id AND a.situacao <> 'CANCELADA') AS atas,
                (SELECT COUNT(DISTINCT ap.unidade_compradora_id) FROM atas_participantes ap JOIN atas_registro_precos a ON a.id = ap.ata_id
                    WHERE a.processo_id = p.id AND ap.tipo = 'PARTICIPANTE') AS participantes")
            ->orderBy(self::ORDENACAO[$ordem] ?? 'p.data_publicacao', $direcao)->orderBy('p.id');

        $p = $q->paginate($porPagina, ['*'], 'pagina', $pagina);
        $p->getCollection()->transform(fn ($r) => [
            'id' => (int) $r->id, 'numero_processo' => $r->numero_processo, 'numero_licitacao' => $r->numero_licitacao,
            'numero_publicacao' => $r->numero_publicacao, 'orgao_gerenciador' => $r->orgao_gerenciador, 'orgao' => $r->orgao,
            'objeto' => $r->objeto, 'modalidade' => $r->modalidade, 'data_publicacao' => $r->data_publicacao, 'data_sessao' => $r->data_sessao,
            'itens' => (int) $r->itens, 'valor_estimado' => Sql::valor($r->valor_estimado), 'valor_adjudicado' => Sql::valor($r->valor_adjudicado),
            'valor_homologado' => Sql::valor($r->valor_homologado), 'fornecedores_vencedores' => (int) $r->fornecedores_vencedores,
            'situacao' => $r->situacao, 'vigencia_ata' => $r->vigencia_ata, 'numero_ata' => $r->numero_ata,
            'atas' => (int) $r->atas, 'participantes' => (int) $r->participantes,
        ]);

        return $p;
    }
}
