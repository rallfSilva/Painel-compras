<?php

namespace App\Services\Indicadores;

use App\Services\Filtros\FiltroService;
use App\Services\Filtros\FiltrosAplicados;
use Illuminate\Support\Facades\DB;

/**
 * Cálculo centralizado dos indicadores do Dashboard (seções 6.1, 27 e 28).
 *
 * Regras:
 *  - Cada grandeza financeira (estimado, adjudicado, homologado, contratado, empenhado, liquidado, pago)
 *    é calculada em separado; nenhuma é somada a outra.
 *  - Dado ausente retorna null ("Não informado"), nunca zero.
 *  - Economia só considera itens com estimado e referência informados e, por padrão, só procedimentos
 *    com disputa de preço.
 */
class IndicadorService
{
    /** Situações de item que representam resultado (compra efetiva ou adjudicada). */
    public const SITUACOES_RESULTADO = ['ADJUDICADO', 'HOMOLOGADO', 'CONTRATADO'];

    public function __construct(private readonly FiltroService $filtros) {}

    /** @return array<string, int|float|null> */
    public function calcular(FiltrosAplicados $f, OpcoesCalculo $o): array
    {
        $valores = $this->doItens($f, $o)
            + $this->atas($f)
            + $this->execucaoFinanceira($f)
            + $this->contratos($f, $o);

        $valores['percentual_economia'] = ($valores['_estimado_comparavel'] ?? 0) > 0
            ? round($valores['economia'] / $valores['_estimado_comparavel'] * 100, 2)
            : null;
        unset($valores['_estimado_comparavel']);

        return $valores;
    }

    /** @return array<string, int|float|null> */
    private function doItens(FiltrosAplicados $f, OpcoesCalculo $o): array
    {
        $res = "pi.situacao IN ('".implode("','", self::SITUACOES_RESULTADO)."')";
        $ref = $o->colunaReferencia();
        $disputa = $o->incluirSemDisputa ? '1 = 1' : 's.com_disputa = 1';
        $comparavel = "$res AND pi.valor_total_estimado IS NOT NULL AND $ref IS NOT NULL AND $disputa";

        $r = $this->filtros->baseItens($f)->selectRaw("
            SUM(CASE WHEN $res THEN pi.valor_total_estimado END) AS total_estimado,
            SUM(pi.valor_total_contratado) AS total_contratado,
            SUM(pi.valor_total_homologado) AS total_homologado,
            SUM(CASE WHEN $comparavel THEN pi.valor_total_estimado - $ref END) AS economia,
            SUM(CASE WHEN $comparavel THEN pi.valor_total_estimado END) AS _estimado_comparavel,
            COUNT(DISTINCT p.unidade_compradora_id) AS unidades_compradoras,
            COUNT(DISTINCT CASE WHEN $res THEN pi.item_catalogo_id END) AS itens_catalogo,
            SUM(CASE WHEN $res THEN 1 ELSE 0 END) AS itens_comprados,
            COUNT(DISTINCT p.id) AS processos,
            COUNT(DISTINCT pi.fornecedor_id) AS fornecedores,
            SUM(CASE WHEN s.tipo = 'LICITACAO' AND $res THEN pi.valor_total_estimado END) AS total_licitado,
            COUNT(DISTINCT CASE WHEN s.tipo = 'LICITACAO' THEN p.id END) AS qtd_licitacoes,
            COUNT(DISTINCT CASE WHEN s.codigo = 'DISPENSA' THEN p.id END) AS qtd_dispensas,
            COUNT(DISTINCT CASE WHEN s.codigo = 'INEXIGIBILIDADE' THEN p.id END) AS qtd_inexigibilidades,
            COUNT(DISTINCT CASE WHEN s.codigo = 'CHAMADA_PNAE' THEN p.id END) AS qtd_chamadas_publicas
        ")->first();

        $out = [];
        foreach ((array) $r as $k => $v) {
            $out[$k] = $v === null ? null : (str_starts_with($k, 'qtd_') || in_array($k, ['unidades_compradoras', 'itens_catalogo', 'itens_comprados', 'processos', 'fornecedores'], true)
                ? (int) $v : round((float) $v, 2));
        }
        // Sem nenhum item no recorte, contagens são zero de fato; somas ficam "Não informado".
        foreach (['unidades_compradoras', 'itens_catalogo', 'itens_comprados', 'processos', 'fornecedores',
            'qtd_licitacoes', 'qtd_dispensas', 'qtd_inexigibilidades', 'qtd_chamadas_publicas'] as $k) {
            $out[$k] ??= 0;
        }
        $out['economia'] ??= null;

        return $out;
    }

    /** @return array{qtd_atas:int} */
    private function atas(FiltrosAplicados $f): array
    {
        $n = DB::table('atas_registro_precos')
            ->where('situacao', '!=', 'CANCELADA')
            ->whereIn('processo_id', $this->filtros->idsProcessos($f))
            ->count();

        return ['qtd_atas' => $n];
    }

    /** @return array<string, float|null> */
    private function execucaoFinanceira(FiltrosAplicados $f): array
    {
        $vazio = ['total_empenhado' => null, 'total_liquidado' => null, 'total_pago' => null];
        if (! DB::table('contratos_execucao_financeira')->exists()) {
            return $vazio; // sem integração com o sistema financeiro: "Não informado"
        }

        $ids = $this->filtros->idsProcessos($f);
        $somas = DB::table('contratos_execucao_financeira as e')
            ->leftJoin('contratos as c', 'c.id', '=', 'e.contrato_id')
            ->where(fn ($w) => $w->whereIn('e.processo_id', $ids)->orWhereIn('c.processo_id', $this->filtros->idsProcessos($f)))
            ->groupBy('e.tipo')
            ->selectRaw('e.tipo, SUM(e.valor) AS total')
            ->pluck('total', 'tipo');

        return [
            'total_empenhado' => round((float) ($somas['EMPENHO'] ?? 0), 2),
            'total_liquidado' => round((float) ($somas['LIQUIDACAO'] ?? 0), 2),
            'total_pago' => round((float) ($somas['PAGAMENTO'] ?? 0), 2),
        ];
    }

    /**
     * Contratos vigentes na data de hoje. Usa os filtros de órgão, fornecedor etc., mas não o período,
     * porque vigência é uma situação atual.
     *
     * @return array{contratos_vigentes:int, contratos_vencendo:int}
     */
    private function contratos(FiltrosAplicados $f, OpcoesCalculo $o): array
    {
        $hoje = $f->hoje();
        $base = DB::table('contratos')
            ->where('situacao', 'VIGENTE')
            ->where('vigencia_fim', '>=', $hoje->toDateString())
            ->whereIn('processo_id', $this->filtros->idsProcessos($f->semPeriodo()));

        return [
            'contratos_vigentes' => (clone $base)->count(),
            'contratos_vencendo' => (clone $base)->where('vigencia_fim', '<=', $hoje->addDays($o->diasVencimentoContratos)->toDateString())->count(),
        ];
    }
}
