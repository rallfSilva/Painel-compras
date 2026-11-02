<?php

namespace App\Services\Indicadores;

use App\Models\GlossarioIndicador;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;

/** Monta a resposta do Dashboard: valor atual, anterior, variação, tendência e explicação de cada indicador. */
class DashboardService
{
    /** Ordem de exibição (seção 6.1). */
    public const ORDEM = [
        'total_estimado', 'total_contratado', 'economia', 'percentual_economia', 'unidades_compradoras',
        'itens_catalogo', 'itens_comprados', 'processos', 'fornecedores', 'total_licitado', 'total_homologado',
        'total_empenhado', 'total_liquidado', 'total_pago', 'qtd_licitacoes', 'qtd_dispensas',
        'qtd_inexigibilidades', 'qtd_chamadas_publicas', 'qtd_atas', 'contratos_vigentes', 'contratos_vencendo',
    ];

    /** Indicadores que não variam com o período (não têm "período anterior"). */
    private const SEM_COMPARACAO = ['contratos_vigentes', 'contratos_vencendo'];

    public function __construct(
        private readonly IndicadorService $indicadores,
        private readonly FiltroService $filtros,
    ) {}

    public function indicadores(FiltrosAplicados $f, OpcoesCalculo $o): array
    {
        $atual = $this->indicadores->calcular($f, $o);
        $filtrosAnterior = $f->periodoAnterior();
        $anterior = $filtrosAnterior ? $this->indicadores->calcular($filtrosAnterior, $o) : null;
        $glossario = GlossarioIndicador::all()->keyBy('codigo');

        $lista = [];
        foreach (self::ORDEM as $codigo) {
            $g = $glossario[$codigo] ?? null;
            $valor = $atual[$codigo] ?? null;
            $valorAnterior = ($anterior && ! in_array($codigo, self::SEM_COMPARACAO, true)) ? ($anterior[$codigo] ?? null) : null;
            $lista[] = [
                'codigo' => $codigo,
                'nome' => $g?->nome ?? $codigo,
                'unidade' => $g?->unidade ?? 'QUANTIDADE',
                'valor' => $valor,
                'informado' => $valor !== null,
                'valor_anterior' => $valorAnterior,
                'variacao_percentual' => self::variacao($valor, $valorAnterior),
                'tendencia' => self::tendencia($valor, $valorAnterior),
                'explicacao' => $g ? [
                    'descricao' => $g->descricao, 'formula' => $g->formula, 'fonte' => $g->fonte,
                    'data_referencia' => $g->data_referencia, 'criterio_inclusao' => $g->criterio_inclusao,
                    'criterio_exclusao' => $g->criterio_exclusao,
                ] : null,
            ];
        }

        return [
            'indicadores' => $lista,
            'periodo' => $f->descricaoPeriodo(),
            'periodo_anterior' => $filtrosAnterior?->descricaoPeriodo(),
            'opcoes' => [
                'economia_base' => $o->baseEconomia,
                'incluir_sem_disputa' => $o->incluirSemDisputa,
                'dias_vencimento_contratos' => $o->diasVencimentoContratos,
            ],
        ];
    }

    public static function variacao(int|float|null $atual, int|float|null $anterior): ?float
    {
        if ($atual === null || $anterior === null || (float) $anterior == 0.0) {
            return null;
        }

        return round(($atual - $anterior) / abs($anterior) * 100, 2);
    }

    public static function tendencia(int|float|null $atual, int|float|null $anterior): ?string
    {
        if ($atual === null || $anterior === null) {
            return null;
        }

        return $atual > $anterior ? 'alta' : ($atual < $anterior ? 'baixa' : 'estavel');
    }

    /** Evolução anual: estimado, contratado e % economia por ano (gráfico do Dashboard). */
    public function evolucaoAnual(FiltrosAplicados $f, OpcoesCalculo $o): array
    {
        $res = "pi.situacao IN ('".implode("','", IndicadorService::SITUACOES_RESULTADO)."')";
        $ref = $o->colunaReferencia();
        $disputa = $o->incluirSemDisputa ? '1 = 1' : 's.com_disputa = 1';
        $comp = "$res AND pi.valor_total_estimado IS NOT NULL AND $ref IS NOT NULL AND $disputa";

        return $this->filtros->baseItens($f)
            ->groupBy('p.ano')->orderBy('p.ano')
            ->selectRaw("p.ano,
                SUM(CASE WHEN $res THEN pi.valor_total_estimado END) AS estimado,
                SUM(pi.valor_total_contratado) AS contratado,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado - $ref END) AS economia,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado END) AS estimado_comparavel,
                COUNT(DISTINCT p.id) AS processos")
            ->get()
            ->map(fn ($r) => [
                'ano' => (int) $r->ano,
                'estimado' => $r->estimado !== null ? round((float) $r->estimado, 2) : null,
                'contratado' => $r->contratado !== null ? round((float) $r->contratado, 2) : null,
                'economia' => $r->economia !== null ? round((float) $r->economia, 2) : null,
                'percentual_economia' => (float) $r->estimado_comparavel > 0 ? round($r->economia / $r->estimado_comparavel * 100, 2) : null,
                'processos' => (int) $r->processos,
            ])->all();
    }

    /** Compras por sistemática (valor contratado e quantidade de processos). */
    public function porSistematica(FiltrosAplicados $f): array
    {
        return $this->filtros->baseItens($f)
            ->groupBy('s.id', 's.nome', 's.ordem')->orderBy('s.ordem')
            ->selectRaw('s.id, s.nome, SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT p.id) AS processos')
            ->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'nome' => $r->nome, 'contratado' => $r->contratado !== null ? round((float) $r->contratado, 2) : null, 'processos' => (int) $r->processos])
            ->all();
    }

    /** Ranking das unidades compradoras por valor contratado. */
    public function topUnidades(FiltrosAplicados $f, int $limite = 10): array
    {
        $total = (float) $this->filtros->baseItens($f)->sum('pi.valor_total_contratado');

        return $this->filtros->baseItens($f)
            ->groupBy('uc.id', 'uc.nome', 'o.sigla')
            ->selectRaw('uc.id, uc.nome, o.sigla AS orgao, SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT p.id) AS processos')
            ->havingRaw('SUM(pi.valor_total_contratado) IS NOT NULL')
            ->orderByDesc('contratado')->limit($limite)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'nome' => $r->nome, 'orgao' => $r->orgao,
                'contratado' => round((float) $r->contratado, 2), 'processos' => (int) $r->processos,
                'percentual' => $total > 0 ? round($r->contratado / $total * 100, 2) : null,
            ])->all();
    }

    /** Participação por grupo de porte (MPE / Outro / Não identificado) no valor contratado. */
    public function porGrupoPorte(FiltrosAplicados $f): array
    {
        $linhas = $this->filtros->baseItens($f)
            ->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')
            ->whereNotNull('pi.valor_total_contratado')
            ->groupBy('grupo')
            ->selectRaw("COALESCE(pe.grupo, 'NI') AS grupo, SUM(pi.valor_total_contratado) AS contratado")
            ->pluck('contratado', 'grupo');
        $total = (float) $linhas->sum();
        $nomes = ['MPE' => 'MPE', 'OUTRO' => 'Outro', 'NI' => 'Não identificado'];

        return collect($nomes)->map(fn ($nome, $g) => [
            'grupo' => $g, 'nome' => $nome, 'contratado' => round((float) ($linhas[$g] ?? 0), 2),
            'percentual' => $total > 0 ? round(($linhas[$g] ?? 0) / $total * 100, 2) : null,
        ])->values()->all();
    }
}
