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
            // Etapa 3A — Compras de MPE (seção 12)
            ['mpe_percentual', '% de participação MPE', 'Participação de MEI, ME e EPP no valor contratado. Produtores familiares (PAF) e cooperativas ficam em categoria própria.', 'Σ contratado de MPE / Σ contratado dos itens com fornecedor informado × 100', 'PERCENTUAL', $fonteItens, $dataRef, 'Itens com valor contratado e fornecedor informado.', 'Itens sem fornecedor.'],
            ['mpe_compras_exclusivas', 'Compras exclusivas MPE', 'Itens com participação exclusiva de MPE (LC 123/2006, art. 48, I).', 'Σ contratado dos itens com benefício EXCLUSIVO', 'BRL', $fonteItens, $dataRef, null, null],
            ['mpe_cota_reservada', 'Itens com cota reservada', 'Itens com cota reservada para MPE (LC 123/2006, art. 48, III).', 'Σ contratado dos itens com benefício COTA_RESERVADA', 'BRL', $fonteItens, $dataRef, null, null],
            // Compras de Alimentos (seção 13)
            ['alimentos_valor', 'Valor adquirido de alimentos', 'Valor contratado dos itens de gêneros alimentícios.', 'Σ contratado onde o item tem categoria de alimento ou o processo tem natureza "Gêneros alimentícios"', 'BRL', $fonteItens, $dataRef, null, null],
            ['alimentos_preco_medio', 'Preço médio do alimento', 'Preço médio por item e unidade de fornecimento.', 'Σ valor adjudicado / Σ quantidade adjudicada (mesmo item e unidade)', 'BRL', $fonteItens, $dataRef, 'Itens com resultado.', 'Unidades de fornecimento diferentes nunca são somadas.'],
            ['pnae_percentual_af', '% agricultura familiar (PNAE)', 'Aproximação do percentual mínimo de compras da agricultura familiar (Lei nº 11.947/2009, art. 14). O percentual legal é calculado sobre os repasses do FNDE, ainda não integrados.', 'Σ contratado via Chamada Pública PNAE ou de fornecedor PAF/cooperativa / Σ contratado de alimentos × 100', 'PERCENTUAL', $fonteItens, $dataRef, null, null],
            // Licitações para RP (seção 14)
            ['rp_total_adjudicado', 'Total Adjudicado RP', 'Valor adjudicado dos processos com Registro de Preços.', 'Σ valor_total_adjudicado onde registro_precos = sim', 'BRL', $fonteItens, $dataRef, null, null],
            ['rp_percentual_economia', '% Economia (licitações RP)', 'Economia na formação das atas.', 'Σ (estimado − adjudicado) / Σ estimado, nos itens com os dois valores × 100', 'PERCENTUAL', $fonteItens, $dataRef, null, null],
            ['rp_atas_vencendo', 'Atas próximas do vencimento', 'Atas vigentes que vencem dentro do prazo configurado (padrão 90 dias).', 'COUNT(atas vigentes com vigência_fim ≤ hoje + N dias)', 'QUANTIDADE', 'Atas de registro de preços', 'Data de hoje', null, 'Atas canceladas.'],
            // Aquisições por RP (seção 15)
            ['arp_total_estimado', 'Total Estimado RP', 'Valor estimado dos itens registrados nas atas.', 'Σ quantidade registrada × valor unitário estimado', 'BRL', 'Itens das atas (atas_itens)', 'Vigência da ata', null, 'Atas canceladas.'],
            ['arp_total_registrado', 'Total Registrado', 'Valor registrado nas atas.', 'Σ quantidade registrada × valor unitário registrado', 'BRL', 'Itens das atas (atas_itens)', 'Vigência da ata', null, 'Atas canceladas.'],
            ['arp_total_comprado', 'Total Comprado (atas)', 'Valor efetivamente adquirido nas atas.', 'Σ valor das utilizações (atas_utilizacoes)', 'BRL', 'Utilizações das atas', 'Data da utilização', null, null],
            ['arp_economia_estimada', 'Economia Estimada RP', 'Diferença entre o estimado e o registrado nas atas.', 'Total Estimado RP − Total Registrado', 'BRL', 'Itens das atas (atas_itens)', 'Vigência da ata', null, null],
            ['arp_percentual_economia', '% Economia RP', 'Economia estimada em relação ao estimado.', 'Economia Estimada RP / Total Estimado RP × 100', 'PERCENTUAL', 'Itens das atas (atas_itens)', 'Vigência da ata', null, null],
            ['arp_economia_efetiva', 'Economia Efetiva', 'Economia proporcional ao que foi efetivamente comprado nas atas.', 'Σ quantidade utilizada × (valor unitário estimado − valor unitário registrado)', 'BRL', 'Itens e utilizações das atas', 'Data da utilização', null, null],
            ['arp_percentual_utilizado', '% Utilizado', 'Quanto do valor registrado já foi comprado.', 'Total Comprado / Total Registrado × 100', 'PERCENTUAL', 'Itens e utilizações das atas', 'Data da utilização', null, null],
            ['arp_participacoes', 'Participações', 'Vínculos entre órgão participante (ou aderente) e item registrado.', 'COUNT(atas_participantes com item, exceto gerenciador)', 'QUANTIDADE', 'Participantes das atas', 'Vigência da ata', null, 'O órgão gerenciador.'],
            ['arp_percentual_saldo', '% Saldo do órgão participante', 'Parte do valor previsto para o órgão ainda não adquirida. Negativo indica aquisição acima do previsto.', '(Σ quantidade prevista × unitário registrado − valor adquirido) / previsto × 100', 'PERCENTUAL', 'Participantes e utilizações das atas', 'Data da utilização', null, null],
            // Sistemática (seção 11)
            ['sist_percentual_disputa', '% disputa efetiva', 'Processos em que houve concorrência real de fornecedores.', 'Processos com 2 ou mais participantes / processos × 100', 'PERCENTUAL', 'Processos', $dataRef, null, null],
            ['sist_tempo_medio', 'Tempo médio de conclusão', 'Duração média dos processos concluídos.', 'Média de (data de homologação − data de abertura), em dias', 'QUANTIDADE', 'Processos', $dataRef, 'Processos concluídos com data de homologação.', null],
            // Itens adjudicados, catálogo e análise do total (seções 16 a 19)
            ['item_percentual_economia', '% economia do item', 'Economia obtida no item em relação ao estimado.', '(valor total estimado − valor total adjudicado) / valor total estimado × 100', 'PERCENTUAL', $fonteItens, $dataRef, 'Itens com valor estimado e adjudicado.', null],
            ['catalogo_duplicidade', 'Duplicidade no catálogo', 'Itens com a mesma descrição normalizada e a mesma unidade de fornecimento.', 'Descrição em maiúsculas, sem acentos e pontuação, com abreviações padronizadas; agrupada por unidade', 'QUANTIDADE', 'Catálogo de itens', 'Não se aplica', null, null],
            ['catalogo_semelhanca', 'Semelhança entre itens', 'Proximidade entre descrições de itens da mesma classe. Semelhança não é equivalência.', 'Termos em comum / termos distintos das duas descrições normalizadas × 100 (Jaccard)', 'PERCENTUAL', 'Catálogo de itens', 'Não se aplica', 'Itens da mesma classe com semelhança ≥ 50%.', 'Palavras de ligação (de, da, do, e...).'],
            ['concentracao_hhi', 'Índice HHI de concentração', 'Mede a concentração do valor contratado entre fornecedores (0 a 10.000). Acima de 2.500 indica mercado concentrado.', 'Σ (participação % de cada fornecedor no valor)²', 'QUANTIDADE', $fonteItens, $dataRef, 'Itens com fornecedor e valor contratado.', null],
            ['compra_recorrente', 'Compra recorrente', 'Mesmo item e unidade de fornecimento comprados em vários processos. Indica oportunidade de planejamento, não fracionamento.', 'COUNT(processos distintos por item e unidade) ≥ mínimo configurado (padrão 3)', 'QUANTIDADE', $fonteItens, $dataRef, 'Itens com resultado.', null],
            // Análise de preços (seção 24)
            ['preco_mediana', 'Mediana do preço', 'Valor central dos preços adjudicados do mesmo item e unidade.', 'Mediana dos valores unitários adjudicados na janela', 'BRL', $fonteItens, 'Data de abertura, na janela configurada (padrão 24 meses)', 'Mesmo item, mesma unidade de fornecimento, itens com resultado.', 'Unidades de fornecimento diferentes.'],
            ['preco_cv', 'Coeficiente de variação do preço', 'Dispersão relativa dos preços do mesmo item e unidade.', 'Desvio padrão amostral / média × 100', 'PERCENTUAL', $fonteItens, 'Janela de análise de preços', null, null],
            ['preco_variacao', 'Variação mínimo → máximo', 'Diferença entre o maior e o menor preço do item na janela.', '(máximo − mínimo) / mínimo × 100', 'PERCENTUAL', $fonteItens, 'Janela de análise de preços', null, null],
            ['preco_alerta', 'Alerta de preço', 'Sinaliza preços para análise humana: desvio da mediana, dispersão alta, referências insuficientes ou aumento brusco. Não constitui conclusão sobre sobrepreço.', '|preço − mediana| / mediana × 100 ≥ limite; CV ≥ limite; referências < mínimo; mediana anual subiu ≥ limite', 'QUANTIDADE', $fonteItens, 'Janela de análise de preços', null, null],
            // PCA e contratos (seções 20 e 22)
            ['pca_planejado', 'Valor total planejado (PCA)', 'Soma do valor estimado das demandas do Plano de Contratações Anual.', 'Σ valor estimado das demandas, exceto as excluídas do plano', 'BRL', 'Demandas do PCA', 'Exercício do plano', null, 'Demandas excluídas do plano.'],
            ['pca_percentual_executado', 'Percentual executado do PCA', 'Quanto do planejado já foi contratado.', 'Σ valor contratado dos processos vinculados às demandas / Σ planejado × 100', 'PERCENTUAL', 'Demandas do PCA e processos vinculados', 'Exercício do plano', 'Processos vinculados a uma demanda (pca_execucao).', null],
            ['pca_atrasada', 'Demanda atrasada', 'Demanda cuja data pretendida já passou sem conclusão.', 'data pretendida < hoje e situação de execução não iniciada ou em andamento', 'QUANTIDADE', 'Demandas do PCA', 'Data de hoje', null, 'Demandas concluídas ou canceladas.'],
            ['pca_fora', 'Contratações fora do PCA', 'Processos que não estão vinculados a nenhuma demanda do plano. Pede análise; não indica irregularidade.', 'COUNT(processos sem vínculo em pca_execucao)', 'QUANTIDADE', 'Processos', $dataRef, null, null],
            ['contrato_percentual_acrescimo', '% de acréscimo do contrato', 'Acréscimos por aditivo em relação ao valor inicial, comparados ao limite do art. 125 da Lei 14.133/2021 (25%; 50% em reforma).', 'Σ valor dos aditivos de valor ou mistos / valor inicial × 100', 'PERCENTUAL', 'Contratos e aditivos', 'Data de assinatura do aditivo', null, 'Aditivos só de prazo.'],
            ['contrato_sinalizado', 'Contrato sinalizado por acréscimo', 'Contrato com acréscimo a partir de 80% do limite configurado. Apoio à fiscalização; não é conclusão jurídica.', '% de acréscimo ≥ limite × percentual de alerta / 100', 'QUANTIDADE', 'Contratos e aditivos', 'Data de hoje', null, null],
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
