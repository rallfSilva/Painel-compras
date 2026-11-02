<?php

namespace App\Services\Inteligencia;

use App\Services\Compras\ComprasTotaisService;
use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use Illuminate\Database\Query\Builder;

/**
 * MÓDULO COMPRAS DE ALIMENTOS (seção 13).
 *
 * Um item é alimento quando o item de catálogo tem categoria de alimento ou quando o processo é da
 * natureza "Gêneros alimentícios". Agricultura familiar = sistemática Chamada Pública PNAE ou fornecedor
 * de porte PAF/COOP.
 *
 * O percentual mínimo do PNAE (Lei nº 11.947/2009, art. 14) é calculado sobre os recursos repassados pelo
 * FNDE. Como o painel não recebe esses repasses, o acompanhamento usa uma aproximação: a participação da
 * agricultura familiar no valor contratado de alimentos. A interface identifica isso como aproximação.
 */
class AlimentosService
{
    public const CATEGORIAS = [
        'CARNES' => 'Carnes', 'AVES' => 'Aves', 'PEIXES' => 'Peixes', 'FRUTAS' => 'Frutas', 'VERDURAS' => 'Verduras',
        'LEGUMES' => 'Legumes', 'CEREAIS' => 'Cereais', 'GRAOS' => 'Grãos', 'LATICINIOS' => 'Laticínios',
        'INDUSTRIALIZADOS' => 'Produtos industrializados', 'OUTROS' => 'Outros',
    ];

    public function __construct(
        private readonly FiltroService $filtros,
        private readonly ComprasTotaisService $compras,
        private readonly Configuracoes $config,
    ) {}

    /** Itens de alimentos com os mesmos filtros globais. */
    public function base(FiltrosAplicados $f): Builder
    {
        return self::restringir($this->filtros->baseItens($f));
    }

    public static function restringir(Builder $q): Builder
    {
        return $q->leftJoin('classificacoes_natureza as cna', 'cna.id', '=', 'p.classificacao_natureza_id')
            ->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')
            ->where(fn (Builder $w) => $w->whereNotNull('ic.categoria_alimento')->orWhere('cna.codigo', 'ALIMENTOS'));
    }

    private static function af(): string
    {
        return "(s.codigo = 'CHAMADA_PNAE' OR ".Sql::AGRICULTURA_FAMILIAR.')';
    }

    public function painel(FiltrosAplicados $f): array
    {
        $res = Sql::resultado();
        $af = self::af();
        $r = $this->base($f)->selectRaw("
            SUM(pi.valor_total_contratado) AS valor,
            SUM(CASE WHEN $res THEN pi.quantidade_adjudicada END) AS quantidade,
            COUNT(DISTINCT CASE WHEN $res THEN pi.item_catalogo_id END) AS itens,
            COUNT(DISTINCT o.id) AS orgaos,
            COUNT(DISTINCT pi.fornecedor_id) AS fornecedores,
            COUNT(DISTINCT fo.municipio_id) AS municipios_origem,
            COUNT(DISTINCT p.id) AS processos,
            SUM(CASE WHEN s.codigo = 'CHAMADA_PNAE' THEN pi.valor_total_contratado END) AS valor_pnae,
            SUM(CASE WHEN ".Sql::AGRICULTURA_FAMILIAR." THEN pi.valor_total_contratado END) AS valor_paf,
            SUM(CASE WHEN $af THEN pi.valor_total_contratado END) AS valor_af,
            SUM(CASE WHEN ic.perecivel = 1 THEN pi.valor_total_contratado END) AS pereciveis,
            SUM(CASE WHEN ic.perecivel = 0 THEN pi.valor_total_contratado END) AS nao_pereciveis
        ")->first();

        $minimo = (float) ($this->config->get('pnae.percentual_minimo_af', '30') ?? 30);
        $pctAf = Sql::pct($r->valor_af, $r->valor);

        return [
            'indicadores' => [
                'valor' => Sql::valor($r->valor),
                'quantidade' => $r->quantidade !== null ? round((float) $r->quantidade, 2) : null,
                'itens' => (int) $r->itens,
                'orgaos' => (int) $r->orgaos,
                'fornecedores' => (int) $r->fornecedores,
                'municipios_origem' => (int) $r->municipios_origem,
                'processos' => (int) $r->processos,
                'valor_pnae' => Sql::valor($r->valor_pnae ?? 0),
                'percentual_pnae' => Sql::pct($r->valor_pnae, $r->valor),
                'valor_paf' => Sql::valor($r->valor_paf ?? 0),
                'percentual_paf' => Sql::pct($r->valor_paf, $r->valor),
                'valor_agricultura_familiar' => Sql::valor($r->valor_af ?? 0),
                'percentual_agricultura_familiar' => $pctAf,
                'valor_pereciveis' => Sql::valor($r->pereciveis ?? 0),
                'valor_nao_pereciveis' => Sql::valor($r->nao_pereciveis ?? 0),
            ],
            'pnae' => [
                'percentual_minimo' => $minimo,
                'percentual_aproximado' => $pctAf,
                'atingido' => $pctAf !== null ? $pctAf >= $minimo : null,
                'base_legal' => 'Lei nº 11.947/2009, art. 14',
                'observacao' => 'Aproximação: participação da agricultura familiar (Chamada Pública PNAE ou fornecedor PAF/cooperativa) no valor contratado de alimentos. O percentual legal é calculado sobre os recursos repassados pelo FNDE, que ainda não estão integrados ao painel.',
            ],
            'por_categoria' => $this->porCategoria($f),
            'perecibilidade' => [
                ['grupo' => 'PERECIVEL', 'nome' => 'Perecíveis', 'contratado' => Sql::valor($r->pereciveis ?? 0), 'percentual' => Sql::pct($r->pereciveis, $r->valor)],
                ['grupo' => 'NAO_PERECIVEL', 'nome' => 'Não perecíveis', 'contratado' => Sql::valor($r->nao_pereciveis ?? 0), 'percentual' => Sql::pct($r->nao_pereciveis, $r->valor)],
                ['grupo' => 'NI', 'nome' => 'Não classificado', 'contratado' => Sql::valor(max(0, (float) $r->valor - (float) $r->pereciveis - (float) $r->nao_pereciveis)), 'percentual' => Sql::pct(max(0, (float) $r->valor - (float) $r->pereciveis - (float) $r->nao_pereciveis), $r->valor)],
            ],
            'por_item' => $this->porItem($f),
            'por_orgao' => $this->compras->agrupar($f, 'o.id', 'COALESCE(o.sigla, o.nome)', 'unidade', 'o', 12, fn (Builder $q) => self::restringir($q)),
            'por_municipio_origem' => $this->compras->agrupar($f, 'mf.id', 'mf.nome', 'fornecedor_local', 'm', 15, fn (Builder $q) => self::restringir($q)),
            'por_fornecedor' => $this->compras->agrupar($f, 'fo.id', 'fo.razao_social', 'fornecedor', 'fo', 12, fn (Builder $q) => self::restringir($q)),
            'evolucao_agricultura_familiar' => $this->evolucaoAf($f, $minimo),
        ];
    }

    private function porCategoria(FiltrosAplicados $f): array
    {
        $cat = "COALESCE(ic.categoria_alimento, 'OUTROS')";
        $linhas = $this->base($f)->whereNotNull('pi.valor_total_contratado')
            ->groupByRaw($cat)
            ->selectRaw("$cat AS categoria, SUM(pi.valor_total_contratado) AS valor, COUNT(DISTINCT p.id) AS processos,
                SUM(CASE WHEN ".self::af().' THEN pi.valor_total_contratado END) AS af')
            ->get()->keyBy('categoria');
        $total = (float) $linhas->sum('valor');

        return collect(self::CATEGORIAS)
            ->filter(fn ($n, $c) => isset($linhas[$c]))
            ->map(fn ($nome, $c) => [
                'categoria' => $c, 'nome' => $nome, 'contratado' => Sql::valor($linhas[$c]->valor),
                'processos' => (int) $linhas[$c]->processos, 'percentual' => Sql::pct($linhas[$c]->valor, $total),
                'agricultura_familiar' => Sql::valor($linhas[$c]->af ?? 0),
            ])->sortByDesc('contratado')->values()->all();
    }

    /**
     * Itens de alimentos: valor, quantidade, preços e frequência. Preço é comparado por item e unidade de
     * fornecimento, nunca entre unidades diferentes.
     */
    private function porItem(FiltrosAplicados $f): array
    {
        return $this->base($f)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->where(fn (Builder $w) => $w->whereRaw(Sql::resultado()))
            ->groupBy('pi.item_catalogo_id', 'pi.unidade_medida_id')
            ->selectRaw('pi.item_catalogo_id AS id, MAX(COALESCE(ic.nome_resumido, ic.descricao, pi.descricao)) AS nome, MAX(ic.codigo) AS codigo,
                MAX(um.sigla) AS unidade, MAX(ic.categoria_alimento) AS categoria, MAX(ic.perecivel) AS perecivel,
                SUM(pi.valor_total_contratado) AS valor, SUM(pi.quantidade_adjudicada) AS quantidade,
                SUM(pi.valor_total_adjudicado) AS adjudicado,
                MIN(pi.valor_unitario_adjudicado) AS preco_minimo, MAX(pi.valor_unitario_adjudicado) AS preco_maximo,
                COUNT(DISTINCT p.id) AS frequencia, COUNT(DISTINCT o.id) AS orgaos, COUNT(DISTINCT pi.fornecedor_id) AS fornecedores,
                SUM(CASE WHEN '.self::af().' THEN pi.valor_total_contratado END) AS af')
            ->orderByDesc('valor')->limit(100)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id !== null ? (int) $r->id : null,
                'nome' => $r->nome, 'codigo' => $r->codigo, 'unidade' => $r->unidade,
                'categoria' => self::CATEGORIAS[$r->categoria ?? 'OUTROS'] ?? 'Outros',
                'perecivel' => $r->perecivel === null ? null : (bool) $r->perecivel,
                'contratado' => Sql::valor($r->valor),
                'quantidade' => $r->quantidade !== null ? round((float) $r->quantidade, 2) : null,
                'preco_medio' => (float) $r->quantidade > 0 ? round((float) $r->adjudicado / (float) $r->quantidade, 4) : null,
                'preco_minimo' => $r->preco_minimo !== null ? round((float) $r->preco_minimo, 4) : null,
                'preco_maximo' => $r->preco_maximo !== null ? round((float) $r->preco_maximo, 4) : null,
                'frequencia' => (int) $r->frequencia, 'orgaos' => (int) $r->orgaos, 'fornecedores' => (int) $r->fornecedores,
                'percentual_agricultura_familiar' => Sql::pct($r->af, $r->valor),
                'filtro' => $r->id !== null ? 'item' : null, 'chave' => $r->id !== null ? "i:$r->id" : null,
            ])->all();
    }

    private function evolucaoAf(FiltrosAplicados $f, float $minimo): array
    {
        return $this->base($f)->whereNotNull('pi.valor_total_contratado')
            ->groupBy('p.ano')->orderBy('p.ano')
            ->selectRaw("p.ano, SUM(pi.valor_total_contratado) AS total,
                SUM(CASE WHEN s.codigo = 'CHAMADA_PNAE' THEN pi.valor_total_contratado END) AS pnae,
                SUM(CASE WHEN ".self::af().' THEN pi.valor_total_contratado END) AS af')
            ->get()
            ->map(fn ($r) => [
                'ano' => (int) $r->ano, 'total' => Sql::valor($r->total), 'pnae' => Sql::valor($r->pnae ?? 0),
                'agricultura_familiar' => Sql::valor($r->af ?? 0), 'percentual' => Sql::pct($r->af, $r->total),
                'percentual_minimo' => $minimo,
            ])->all();
    }
}
