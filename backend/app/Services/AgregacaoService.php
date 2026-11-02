<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Recalcula a tabela de resumo mensal (agregados_compras_mensal), usada por séries históricas e por
 * painéis de alto volume. Deve rodar ao final de cada importação ou sincronização (seção 26).
 */
class AgregacaoService
{
    public function recalcular(): int
    {
        return DB::transaction(function () {
            DB::table('agregados_compras_mensal')->delete();
            $agora = now()->toDateTimeString();

            return DB::insert('
                INSERT INTO agregados_compras_mensal
                    (ano, mes, unidade_compradora_id, sistematica_id, porte_id, classe_id, registro_precos, qtd_itens,
                     valor_estimado, valor_adjudicado, valor_homologado, valor_contratado, calculado_em)
                SELECT p.ano, p.mes, p.unidade_compradora_id, p.sistematica_id, fo.porte_id, ic.classe_id, p.registro_precos,
                       COUNT(*), COALESCE(SUM(pi.valor_total_estimado), 0), COALESCE(SUM(pi.valor_total_adjudicado), 0),
                       COALESCE(SUM(pi.valor_total_homologado), 0), COALESCE(SUM(pi.valor_total_contratado), 0), ?
                FROM processos_itens pi
                JOIN processos p ON p.id = pi.processo_id
                LEFT JOIN fornecedores fo ON fo.id = pi.fornecedor_id
                LEFT JOIN itens_catalogo ic ON ic.id = pi.item_catalogo_id
                GROUP BY p.ano, p.mes, p.unidade_compradora_id, p.sistematica_id, fo.porte_id, ic.classe_id, p.registro_precos
            ', [$agora]) ? DB::table('agregados_compras_mensal')->count() : 0;
        });
    }
}
