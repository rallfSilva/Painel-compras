<?php

namespace Database\Seeders;

use App\Models\GlossarioIndicador;
use Illuminate\Database\Seeder;

/** Glossário de indicadores (seção 28). Os códigos são os mesmos usados pela API do Dashboard. */
class GlossarioSeeder extends Seeder
{
    public function run(): void
    {
        $fonteItens = 'Itens dos processos de contratação (processos_itens)';
        $dataRef = 'Data de abertura do processo';
        $itens = [
            ['total_estimado', 'Total Estimado', 'Soma do valor estimado dos itens com resultado no período.', 'Σ valor_total_estimado dos itens', 'BRL', $fonteItens, $dataRef, 'Itens adjudicados, homologados ou contratados.', 'Itens desertos, fracassados ou cancelados.'],
            ['total_contratado', 'Total Contratado', 'Soma do valor contratado dos itens.', 'Σ valor_total_contratado dos itens', 'BRL', $fonteItens, $dataRef, 'Itens com valor contratado informado.', 'Itens sem valor contratado (exibe "Não informado" se nenhum tiver).'],
            ['economia', 'Economia Gerada', 'Diferença entre o valor estimado e o valor de referência (configurável: adjudicado, homologado ou contratado), apenas nos itens que têm os dois valores.', 'Σ (estimado − referência) nos itens comparáveis', 'BRL', $fonteItens, $dataRef, 'Itens com estimado e referência informados, em procedimentos com disputa de preço.', 'Por padrão, procedimentos sem disputa (Chamada Pública PNAE, credenciamento, inexigibilidade, adesão). Pode ser alterado na interface.'],
            ['percentual_economia', '% Economia', 'Economia em relação ao valor estimado dos mesmos itens comparáveis.', 'Economia / Σ estimado dos itens comparáveis × 100', 'PERCENTUAL', $fonteItens, $dataRef, 'Mesmos itens usados na Economia.', 'Mesmos critérios de exclusão da Economia.'],
            ['unidades_compradoras', 'Unidades Compradoras', 'Quantidade de unidades compradoras distintas com processos no período.', 'COUNT(DISTINCT unidade_compradora)', 'QUANTIDADE', 'Processos', $dataRef, null, null],
            ['itens_catalogo', 'Itens do Catálogo', 'Quantidade de itens de catálogo distintos comprados.', 'COUNT(DISTINCT item_catalogo)', 'QUANTIDADE', $fonteItens, $dataRef, 'Itens vinculados ao catálogo.', 'Itens sem código de catálogo.'],
            ['itens_comprados', 'Itens Comprados', 'Quantidade de itens (linhas) com resultado.', 'COUNT(itens com resultado)', 'QUANTIDADE', $fonteItens, $dataRef, null, 'Itens desertos, fracassados ou cancelados.'],
            ['processos', 'Processos', 'Quantidade de processos distintos.', 'COUNT(DISTINCT processo)', 'QUANTIDADE', 'Processos', $dataRef, null, null],
            ['fornecedores', 'Fornecedores', 'Quantidade de fornecedores distintos vencedores.', 'COUNT(DISTINCT fornecedor)', 'QUANTIDADE', $fonteItens, $dataRef, null, null],
            ['total_licitado', 'Total Licitado', 'Valor estimado dos itens de processos de licitação (pregão, concorrência, concurso, leilão, diálogo).', 'Σ valor_total_estimado onde sistemática é licitação', 'BRL', $fonteItens, $dataRef, 'Sistemáticas do tipo LICITACAO.', 'Contratações diretas, chamadas públicas e procedimentos auxiliares.'],
            ['total_adjudicado', 'Total Adjudicado', 'Soma do valor adjudicado dos itens.', 'Σ valor_total_adjudicado', 'BRL', $fonteItens, $dataRef, 'Itens com valor adjudicado informado.', null],
            ['total_homologado', 'Total Homologado', 'Soma do valor homologado dos itens.', 'Σ valor_total_homologado', 'BRL', $fonteItens, $dataRef, null, null],
            ['total_empenhado', 'Total Empenhado', 'Valor empenhado vinculado aos processos filtrados.', 'Σ execução financeira tipo EMPENHO', 'BRL', 'Sistema de execução orçamentária e financeira do Estado', 'Data do empenho', 'Empenhos vinculados a processo ou contrato.', 'Exibe "Não informado" enquanto não houver integração com o sistema financeiro.'],
            ['total_liquidado', 'Total Liquidado', 'Valor liquidado vinculado aos processos filtrados.', 'Σ execução financeira tipo LIQUIDACAO', 'BRL', 'Sistema de execução orçamentária e financeira do Estado', 'Data da liquidação', null, 'Exibe "Não informado" enquanto não houver integração com o sistema financeiro.'],
            ['total_pago', 'Total Pago', 'Valor pago vinculado aos processos filtrados.', 'Σ execução financeira tipo PAGAMENTO', 'BRL', 'Sistema de execução orçamentária e financeira do Estado', 'Data do pagamento', null, 'Exibe "Não informado" enquanto não houver integração com o sistema financeiro.'],
            ['qtd_licitacoes', 'Licitações', 'Processos cuja sistemática é uma modalidade de licitação.', 'COUNT(DISTINCT processo) onde tipo = LICITACAO', 'QUANTIDADE', 'Processos', $dataRef, null, null],
            ['qtd_dispensas', 'Dispensas', 'Processos de dispensa de licitação.', 'COUNT(DISTINCT processo) onde sistemática = DISPENSA', 'QUANTIDADE', 'Processos', $dataRef, null, null],
            ['qtd_inexigibilidades', 'Inexigibilidades', 'Processos de inexigibilidade.', 'COUNT(DISTINCT processo) onde sistemática = INEXIGIBILIDADE', 'QUANTIDADE', 'Processos', $dataRef, null, null],
            ['qtd_chamadas_publicas', 'Chamadas Públicas', 'Processos de Chamada Pública PNAE (agricultura familiar).', 'COUNT(DISTINCT processo) onde sistemática = CHAMADA_PNAE', 'QUANTIDADE', 'Processos', $dataRef, null, null],
            ['qtd_atas', 'Atas de Registro de Preços', 'Atas vinculadas aos processos filtrados.', 'COUNT(DISTINCT ata)', 'QUANTIDADE', 'Atas de registro de preços', $dataRef, null, 'Atas canceladas.'],
            ['contratos_vigentes', 'Contratos Vigentes', 'Contratos em vigor na data de hoje, vinculados aos órgãos e fornecedores filtrados.', 'COUNT(contratos com vigência_fim ≥ hoje e situação VIGENTE)', 'QUANTIDADE', 'Contratos', 'Data de hoje (não depende do período selecionado)', null, 'Contratos encerrados, rescindidos ou suspensos.'],
            ['contratos_vencendo', 'Contratos Próximos do Vencimento', 'Contratos vigentes que vencem dentro do prazo configurado (padrão 90 dias).', 'COUNT(contratos vigentes com vigência_fim ≤ hoje + N dias)', 'QUANTIDADE', 'Contratos', 'Data de hoje (não depende do período selecionado)', null, null],
        ];

        foreach ($itens as [$codigo, $nome, $descricao, $formula, $unidade, $fonte, $data, $incl, $excl]) {
            GlossarioIndicador::updateOrCreate(['codigo' => $codigo], [
                'nome' => $nome, 'descricao' => $descricao, 'formula' => $formula, 'unidade' => $unidade,
                'fonte' => $fonte, 'data_referencia' => $data, 'criterio_inclusao' => $incl, 'criterio_exclusao' => $excl,
            ]);
        }
    }
}
