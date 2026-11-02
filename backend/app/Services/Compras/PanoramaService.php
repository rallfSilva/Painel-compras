<?php

namespace App\Services\Compras;

use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Services\Indicadores\IndicadorService;
use App\Services\Indicadores\OpcoesCalculo;
use Illuminate\Support\Facades\DB;

/**
 * PANORAMA DAS CONTRATAÇÕES POR UNIDADE CONTRATANTE (seção 8): tabela com 14 colunas.
 *
 * Definições:
 *  - Total comprado = soma do valor contratado dos itens.
 *  - Valor "elegível" dos percentuais de MPE, Estado e Região = itens com valor contratado e fornecedor informado.
 *  - % Região: fornecedor da região de planejamento selecionada, ou, sem seleção, da mesma região do comprador.
 *  - % Disputa: por quantidade de processos ou por valor contratado, conforme escolha do usuário.
 *  - Mediana da diferença em relação ao Estado: ver medianasPreco().
 */
class PanoramaService
{
    /** Faixas dos indicadores visuais (verde/amarelo/vermelho). "maior" = quanto maior, melhor. */
    public const FAIXAS = [
        'percentual_economia' => ['sentido' => 'maior', 'bom' => 15, 'atencao' => 5],
        'mediana_diferenca_estado' => ['sentido' => 'menor', 'bom' => 0, 'atencao' => 10],
        'percentual_mpe' => ['sentido' => 'maior', 'bom' => 30, 'atencao' => 15],
        'percentual_estado' => ['sentido' => 'maior', 'bom' => 50, 'atencao' => 25],
        'percentual_disputa' => ['sentido' => 'maior', 'bom' => 70, 'atencao' => 40],
        'percentual_eletronicos' => ['sentido' => 'maior', 'bom' => 90, 'atencao' => 70],
    ];

    public function __construct(
        private readonly FiltroService $filtros,
        private readonly Configuracoes $config,
    ) {}

    /**
     * @param  'quantidade'|'valor'  $metricaDisputa
     */
    public function tabela(FiltrosAplicados $f, OpcoesCalculo $o, string $metricaDisputa = 'quantidade', ?int $regiaoId = null): array
    {
        $res = "pi.situacao IN ('".implode("','", IndicadorService::SITUACOES_RESULTADO)."')";
        $ref = $o->colunaReferencia();
        $disputa = $o->incluirSemDisputa ? '1 = 1' : 's.com_disputa = 1';
        $comp = "$res AND pi.valor_total_estimado IS NOT NULL AND $ref IS NOT NULL AND $disputa";
        $eleg = 'pi.valor_total_contratado IS NOT NULL AND pi.fornecedor_id IS NOT NULL';
        $regiao = $regiaoId ? 'mf.regiao_planejamento_id = '.(int) $regiaoId : 'mf.regiao_planejamento_id = mc.regiao_planejamento_id';

        $linhas = $this->filtros->baseItens($f)
            ->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')
            ->leftJoin('unidades_superiores as us', 'us.id', '=', 'o.unidade_superior_id')
            ->groupBy('uc.id')
            ->selectRaw("uc.id, MAX(uc.nome) AS unidade, MAX(o.sigla) AS orgao, MAX(o.id) AS orgao_id, MAX(us.nome) AS unidade_superior,
                SUM(pi.valor_total_contratado) AS total_comprado,
                COUNT(DISTINCT p.id) AS processos,
                SUM(CASE WHEN $res THEN 1 ELSE 0 END) AS itens,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado - $ref END) AS economia,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado END) AS estimado_comparavel,
                COUNT(DISTINCT pi.fornecedor_id) AS fornecedores,
                SUM(CASE WHEN $eleg THEN pi.valor_total_contratado END) AS elegivel,
                SUM(CASE WHEN $eleg AND pe.grupo = 'MPE' THEN pi.valor_total_contratado END) AS mpe,
                SUM(CASE WHEN $eleg AND ef.uf = 'RR' THEN pi.valor_total_contratado END) AS estado,
                SUM(CASE WHEN $eleg AND $regiao THEN pi.valor_total_contratado END) AS regiao,
                COUNT(DISTINCT CASE WHEN s.com_disputa = 1 THEN p.id END) AS processos_disputa,
                SUM(CASE WHEN s.com_disputa = 1 THEN pi.valor_total_contratado END) AS valor_disputa,
                COUNT(DISTINCT CASE WHEN p.eletronico = 1 THEN p.id END) AS processos_eletronicos")
            ->get();

        $totalGeral = (float) $linhas->sum('total_comprado');
        $medianas = $this->medianasPreco($f);
        $pct = fn ($a, $b) => (float) $b > 0 ? round((float) $a / (float) $b * 100, 2) : null;

        $dados = $linhas->map(fn ($r) => [
            'id' => (int) $r->id,
            'unidade' => $r->unidade,
            'orgao' => $r->orgao,
            'orgao_id' => (int) $r->orgao_id,
            'unidade_superior' => $r->unidade_superior,
            'total_comprado' => $r->total_comprado !== null ? round((float) $r->total_comprado, 2) : null,
            'percentual_total' => $r->total_comprado !== null ? $pct($r->total_comprado, $totalGeral) : null,
            'processos' => (int) $r->processos,
            'itens' => (int) $r->itens,
            'media_itens_processo' => $r->processos > 0 ? round($r->itens / $r->processos, 2) : null,
            'percentual_economia' => $pct($r->economia, $r->estimado_comparavel),
            'mediana_diferenca_estado' => $medianas[(int) $r->id]['mediana'] ?? null,
            'itens_comparados' => $medianas[(int) $r->id]['n'] ?? 0,
            'fornecedores' => (int) $r->fornecedores,
            'percentual_mpe' => $pct($r->mpe, $r->elegivel),
            'percentual_estado' => $pct($r->estado, $r->elegivel),
            'percentual_regiao' => $pct($r->regiao, $r->elegivel),
            'percentual_disputa' => $metricaDisputa === 'valor' ? $pct($r->valor_disputa, $r->total_comprado) : $pct($r->processos_disputa, $r->processos),
            'percentual_eletronicos' => $pct($r->processos_eletronicos, $r->processos),
        ])->sortByDesc('total_comprado')->values()->all();

        return [
            'dados' => $dados,
            'total_geral' => round($totalGeral, 2),
            'faixas' => self::FAIXAS,
            'opcoes' => [
                'metrica_disputa' => $metricaDisputa,
                'regiao_id' => $regiaoId,
                'economia_base' => $o->baseEconomia,
                'incluir_sem_disputa' => $o->incluirSemDisputa,
                'minimo_observacoes_preco' => $this->minimoObservacoes(),
            ],
            'regioes' => DB::table('regioes_planejamento as r')->join('estados as e', 'e.id', '=', 'r.estado_id')->where('e.uf', 'RR')
                ->orderBy('r.nome')->get(['r.id', 'r.nome']),
        ];
    }

    private function minimoObservacoes(): int
    {
        return max(2, $this->config->int('precos.minimo_observacoes', 3));
    }

    /**
     * Mediana, por unidade, da diferença percentual entre o preço unitário pago e a referência estadual.
     *
     * Só compara itens equivalentes: mesmo item de catálogo, mesma unidade de fornecimento e mesmo ano.
     * A referência estadual é a mediana dos preços desse item pagos pelas DEMAIS unidades do Estado (com os
     * demais filtros aplicados), e só existe quando há o mínimo de observações configurado. Itens sem código de
     * catálogo não são comparados, para não confundir produtos semelhantes com especificações diferentes.
     *
     * @return array<int, array{mediana: float|null, n: int}>
     */
    public function medianasPreco(FiltrosAplicados $f): array
    {
        $semUnidade = $f->com('unidade', []);
        $linhas = $this->filtros->baseItens($semUnidade)
            ->whereIn('pi.situacao', IndicadorService::SITUACOES_RESULTADO)
            ->whereNotNull('pi.item_catalogo_id')
            ->whereNotNull('pi.unidade_medida_id')
            ->where('pi.valor_unitario_adjudicado', '>', 0)
            ->select(['p.unidade_compradora_id as unidade', 'pi.item_catalogo_id as item', 'pi.unidade_medida_id as um', 'p.ano', 'pi.valor_unitario_adjudicado as preco'])
            ->get();

        $grupos = [];
        foreach ($linhas as $l) {
            $grupos["$l->item|$l->um|$l->ano"][] = $l;
        }

        $minimo = $this->minimoObservacoes();
        $permitidas = $this->unidadesNoFiltro($f);
        $difs = [];
        foreach ($grupos as $obs) {
            if (count($obs) < $minimo + 1) {
                continue;
            }
            $porUnidade = [];
            foreach ($obs as $o) {
                $porUnidade[(int) $o->unidade][] = (float) $o->preco;
            }
            foreach ($porUnidade as $u => $precos) {
                if ($permitidas !== null && ! isset($permitidas[$u])) {
                    continue;
                }
                // Referência = preços das DEMAIS unidades, para a unidade não ser comparada consigo mesma.
                $outros = [];
                foreach ($porUnidade as $u2 => $p2) {
                    if ($u2 !== $u) {
                        array_push($outros, ...$p2);
                    }
                }
                if (count($outros) < $minimo) {
                    continue;
                }
                $ref = self::mediana($outros);
                if ($ref <= 0) {
                    continue;
                }
                foreach ($precos as $preco) {
                    $difs[$u][] = ($preco - $ref) / $ref * 100;
                }
            }
        }

        $out = [];
        foreach ($difs as $u => $lista) {
            $out[$u] = ['mediana' => round(self::mediana($lista), 2), 'n' => count($lista)];
        }

        return $out;
    }

    /** @return array<int, true>|null unidades que atendem ao filtro de unidade (null = todas) */
    private function unidadesNoFiltro(FiltrosAplicados $f): ?array
    {
        if (! $f->valores('unidade')) {
            return null;
        }

        return $this->filtros->baseItens($f)->distinct()->pluck('p.unidade_compradora_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** @param  list<float>  $valores */
    public static function mediana(array $valores): float
    {
        sort($valores);
        $n = count($valores);
        if ($n === 0) {
            return 0.0;
        }
        $meio = intdiv($n, 2);

        return $n % 2 ? $valores[$meio] : ($valores[$meio - 1] + $valores[$meio]) / 2;
    }
}
