<?php

namespace App\Services\Inteligencia;

use App\Services\Compras\ComprasTotaisService;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * PAINEL POR NATUREZA DA DESPESA / CLASSE DO CATÁLOGO (seção 10).
 * O ranking dos itens agrupa por item de catálogo e unidade de fornecimento, para nunca somar quantidades
 * ou comparar preços entre unidades diferentes.
 */
class NaturezaService
{
    public function __construct(
        private readonly FiltroService $filtros,
        private readonly ComprasTotaisService $compras,
    ) {}

    public function painel(FiltrosAplicados $f): array
    {
        $sub = fn (Builder $q) => $q->leftJoin('subelementos_despesa as sd', 'sd.id', '=', 'pi.subelemento_despesa_id');
        $nat = fn (Builder $q) => $q->leftJoin('subelementos_despesa as sd', 'sd.id', '=', 'pi.subelemento_despesa_id')
            ->leftJoin('elementos_despesa as ed', 'ed.id', '=', 'sd.elemento_despesa_id')
            ->leftJoin('naturezas_despesa as nd', 'nd.id', '=', 'ed.natureza_despesa_id');

        return [
            'por_tipo' => $this->compras->agrupar($f, 'cn.id', 'cn.nome', 'natureza', 'n', 12,
                fn (Builder $q) => $q->leftJoin('classificacoes_natureza as cn', 'cn.id', '=', 'p.classificacao_natureza_id')),
            'por_natureza_despesa' => $this->compras->agrupar($f, 'nd.id', 'nd.nome', 'natureza', 'nd', 12, $nat),
            'por_elemento' => $this->compras->agrupar($f, 'ed.id', 'ed.nome', 'natureza', 'el', 12, $nat),
            'por_subelemento' => $this->compras->agrupar($f, 'sd.id', 'sd.nome', 'natureza', 'se', 15, $sub),
            'por_grupo' => $this->compras->agrupar($f, 'gm.id', 'gm.nome', 'classe', 'g', 12,
                fn (Builder $q) => $q->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')),
            'por_classe' => $this->compras->agrupar($f, 'cm.id', 'cm.nome', 'classe', 'c', 15),
            'ranking_itens' => $this->ranking($f, 50),
        ];
    }

    /** Ranking dos itens: valor, quantidade, frequência e preços, por item e unidade de fornecimento. */
    public function ranking(FiltrosAplicados $f, int $limite): array
    {
        return $this->filtros->baseItens($f)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')
            ->whereRaw(Sql::resultado())->whereNotNull('pi.item_catalogo_id')
            ->groupBy('pi.item_catalogo_id', 'pi.unidade_medida_id')
            ->selectRaw('pi.item_catalogo_id AS id, MAX(ic.codigo) AS codigo, MAX(COALESCE(ic.nome_resumido, ic.descricao)) AS descricao,
                MAX(ic.tipo) AS tipo, MAX(um.sigla) AS unidade, MAX(cm.nome) AS classe, MAX(gm.nome) AS grupo,
                SUM(pi.quantidade_adjudicada) AS quantidade, SUM(pi.valor_total_adjudicado) AS adjudicado, SUM(pi.valor_total_contratado) AS contratado,
                COUNT(DISTINCT p.id) AS frequencia, COUNT(DISTINCT o.id) AS orgaos,
                MIN(pi.valor_unitario_adjudicado) AS preco_minimo, MAX(pi.valor_unitario_adjudicado) AS preco_maximo')
            ->orderByDesc('contratado')->limit($limite)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'codigo' => $r->codigo, 'descricao' => $r->descricao, 'tipo' => $r->tipo === 'S' ? 'Serviço' : 'Material',
                'unidade' => $r->unidade, 'classe' => $r->classe, 'grupo' => $r->grupo,
                'quantidade' => round((float) $r->quantidade, 2), 'contratado' => Sql::valor($r->contratado),
                'preco_medio' => (float) $r->quantidade > 0 ? round((float) $r->adjudicado / (float) $r->quantidade, 4) : null,
                'preco_minimo' => $r->preco_minimo !== null ? round((float) $r->preco_minimo, 4) : null,
                'preco_maximo' => $r->preco_maximo !== null ? round((float) $r->preco_maximo, 4) : null,
                'frequencia' => (int) $r->frequencia, 'orgaos' => (int) $r->orgaos,
                'filtro' => 'item', 'chave' => "i:$r->id",
            ])->all();
    }

    /** Detalhe do item: evolução anual dos preços por unidade de fornecimento e processos em que foi comprado. */
    public function item(int $id, FiltrosAplicados $f): ?array
    {
        $item = DB::table('itens_catalogo as ic')->leftJoin('classes_materiais as cm', 'cm.id', '=', 'ic.classe_id')
            ->where('ic.id', $id)->first(['ic.id', 'ic.codigo', 'ic.descricao', 'ic.especificacao', 'cm.nome as classe']);
        if (! $item) {
            return null;
        }
        $g = $f->com('item', ["i:$id"]);

        $precos = $this->filtros->baseItens($g)->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->whereRaw(Sql::resultado())->whereNotNull('pi.valor_unitario_adjudicado')
            ->groupBy('p.ano', 'pi.unidade_medida_id')->orderBy('p.ano')
            ->selectRaw('p.ano, MAX(um.sigla) AS unidade, SUM(pi.valor_total_adjudicado) AS adjudicado, SUM(pi.quantidade_adjudicada) AS quantidade,
                MIN(pi.valor_unitario_adjudicado) AS minimo, MAX(pi.valor_unitario_adjudicado) AS maximo, COUNT(*) AS compras')
            ->get()
            ->map(fn ($r) => [
                'ano' => (int) $r->ano, 'unidade' => $r->unidade,
                'preco_medio' => (float) $r->quantidade > 0 ? round((float) $r->adjudicado / (float) $r->quantidade, 4) : null,
                'preco_minimo' => round((float) $r->minimo, 4), 'preco_maximo' => round((float) $r->maximo, 4), 'compras' => (int) $r->compras,
            ])->all();

        $processos = $this->filtros->baseItens($g)->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->orderByDesc('p.data_abertura')->limit(100)
            ->get(['p.id', 'p.numero_publicacao', 'p.data_abertura', 'p.objeto', 's.nome as sistematica', 'o.sigla as orgao',
                'pi.quantidade_adjudicada', 'um.sigla as unidade', 'pi.valor_unitario_adjudicado', 'pi.valor_total_contratado', 'pi.situacao', 'fo.razao_social as fornecedor'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'numero_publicacao' => $r->numero_publicacao, 'data_abertura' => $r->data_abertura,
                'objeto' => $r->objeto, 'sistematica' => $r->sistematica, 'orgao' => $r->orgao,
                'quantidade' => $r->quantidade_adjudicada !== null ? (float) $r->quantidade_adjudicada : null, 'unidade' => $r->unidade,
                'valor_unitario' => $r->valor_unitario_adjudicado !== null ? (float) $r->valor_unitario_adjudicado : null,
                'contratado' => Sql::valor($r->valor_total_contratado), 'situacao' => $r->situacao, 'fornecedor' => $r->fornecedor,
            ])->all();

        return [
            'item' => ['id' => (int) $item->id, 'codigo' => $item->codigo, 'descricao' => $item->descricao, 'especificacao' => $item->especificacao, 'classe' => $item->classe],
            'evolucao_precos' => $precos,
            'processos' => $processos,
        ];
    }
}
