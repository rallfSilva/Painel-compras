<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tabelas de referência e classificações (seção 34: seeders das classificações).
 * Dados oficiais e estáveis: UFs, municípios de Roraima (IBGE), portes, regimes, modalidades e sistemáticas.
 */
class ReferenciaSeeder extends Seeder
{
    public function run(): void
    {
        $agora = now();

        $estados = [
            ['RO', 'Rondônia', '11', 'Norte'], ['AC', 'Acre', '12', 'Norte'], ['AM', 'Amazonas', '13', 'Norte'],
            ['RR', 'Roraima', '14', 'Norte'], ['PA', 'Pará', '15', 'Norte'], ['AP', 'Amapá', '16', 'Norte'],
            ['TO', 'Tocantins', '17', 'Norte'], ['MA', 'Maranhão', '21', 'Nordeste'], ['PI', 'Piauí', '22', 'Nordeste'],
            ['CE', 'Ceará', '23', 'Nordeste'], ['RN', 'Rio Grande do Norte', '24', 'Nordeste'], ['PB', 'Paraíba', '25', 'Nordeste'],
            ['PE', 'Pernambuco', '26', 'Nordeste'], ['AL', 'Alagoas', '27', 'Nordeste'], ['SE', 'Sergipe', '28', 'Nordeste'],
            ['BA', 'Bahia', '29', 'Nordeste'], ['MG', 'Minas Gerais', '31', 'Sudeste'], ['ES', 'Espírito Santo', '32', 'Sudeste'],
            ['RJ', 'Rio de Janeiro', '33', 'Sudeste'], ['SP', 'São Paulo', '35', 'Sudeste'], ['PR', 'Paraná', '41', 'Sul'],
            ['SC', 'Santa Catarina', '42', 'Sul'], ['RS', 'Rio Grande do Sul', '43', 'Sul'], ['MS', 'Mato Grosso do Sul', '50', 'Centro-Oeste'],
            ['MT', 'Mato Grosso', '51', 'Centro-Oeste'], ['GO', 'Goiás', '52', 'Centro-Oeste'], ['DF', 'Distrito Federal', '53', 'Centro-Oeste'],
        ];
        foreach ($estados as [$uf, $nome, $cod, $reg]) {
            DB::table('estados')->updateOrInsert(['uf' => $uf], [
                'nome' => $nome, 'codigo_ibge' => $cod, 'regiao_pais' => $reg, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        // Os 15 municípios de Roraima (códigos IBGE). As regiões de planejamento oficiais do Estado
        // devem ser cadastradas pela SELC; o seeder demonstrativo cria regiões fictícias marcadas como tal.
        $rr = DB::table('estados')->where('uf', 'RR')->value('id');
        $municipiosRR = [
            '1400050' => 'Alto Alegre', '1400027' => 'Amajari', '1400100' => 'Boa Vista', '1400159' => 'Bonfim',
            '1400175' => 'Cantá', '1400209' => 'Caracaraí', '1400233' => 'Caroebe', '1400282' => 'Iracema',
            '1400308' => 'Mucajaí', '1400407' => 'Normandia', '1400456' => 'Pacaraima', '1400472' => 'Rorainópolis',
            '1400506' => 'São João da Baliza', '1400605' => 'São Luiz', '1400704' => 'Uiramutã',
        ];
        foreach ($municipiosRR as $cod => $nome) {
            DB::table('municipios')->updateOrInsert(['codigo_ibge' => $cod], [
                'estado_id' => $rr, 'nome' => $nome, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        // Capitais de outras UFs, usadas como município de fornecedores de fora do Estado
        $capitais = [
            '1302603' => ['AM', 'Manaus'], '1501402' => ['PA', 'Belém'], '3550308' => ['SP', 'São Paulo'],
            '5300108' => ['DF', 'Brasília'], '3106200' => ['MG', 'Belo Horizonte'], '4106902' => ['PR', 'Curitiba'],
            '2304400' => ['CE', 'Fortaleza'], '2927408' => ['BA', 'Salvador'], '3304557' => ['RJ', 'Rio de Janeiro'],
            '5208707' => ['GO', 'Goiânia'],
        ];
        foreach ($capitais as $cod => [$uf, $nome]) {
            DB::table('municipios')->updateOrInsert(['codigo_ibge' => $cod], [
                'estado_id' => DB::table('estados')->where('uf', $uf)->value('id'), 'nome' => $nome,
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        $portes = [
            ['MEI', 'Microempreendedor Individual', 'MPE', 1], ['ME', 'Microempresa', 'MPE', 2],
            ['EPP', 'Empresa de Pequeno Porte', 'MPE', 3], ['MEDIO', 'Médio Porte', 'OUTRO', 4],
            ['GRANDE', 'Grande Porte', 'OUTRO', 5], ['PAF', 'Produtor / Agricultor Familiar', 'OUTRO', 6],
            ['COOP', 'Cooperativa', 'OUTRO', 7], ['PF', 'Pessoa Física', 'OUTRO', 8], ['NI', 'Não identificado', 'NI', 9],
        ];
        foreach ($portes as [$c, $n, $g, $o]) {
            DB::table('portes_empresariais')->updateOrInsert(['codigo' => $c], [
                'nome' => $n, 'grupo' => $g, 'ordem' => $o, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        $regimes = [
            ['L8666', 'Lei nº 8.666/1993'], ['L10520', 'Lei nº 10.520/2002 (Pregão)'], ['L14133', 'Lei nº 14.133/2021'],
            ['L11947', 'Lei nº 11.947/2009 (PNAE)'], ['L13303', 'Lei nº 13.303/2016 (Estatais)'], ['OUTRO', 'Outras normas'],
        ];
        foreach ($regimes as [$c, $n]) {
            DB::table('regimes_legais')->updateOrInsert(['codigo' => $c], ['nome' => $n, 'created_at' => $agora, 'updated_at' => $agora]);
        }
        $reg = fn (string $c) => DB::table('regimes_legais')->where('codigo', $c)->value('id');

        $amparos = [
            ['L14133_ART28_I', 'L14133', 'Lei 14.133/2021, art. 28, I (pregão)'],
            ['L14133_ART28_II', 'L14133', 'Lei 14.133/2021, art. 28, II (concorrência)'],
            ['L14133_ART75_I', 'L14133', 'Lei 14.133/2021, art. 75, I (dispensa por valor, obras e serviços de engenharia)'],
            ['L14133_ART75_II', 'L14133', 'Lei 14.133/2021, art. 75, II (dispensa por valor, compras e serviços)'],
            ['L14133_ART75_VIII', 'L14133', 'Lei 14.133/2021, art. 75, VIII (dispensa emergencial)'],
            ['L14133_ART74', 'L14133', 'Lei 14.133/2021, art. 74 (inexigibilidade)'],
            ['L14133_ART79', 'L14133', 'Lei 14.133/2021, art. 79 (credenciamento)'],
            ['L14133_ART86', 'L14133', 'Lei 14.133/2021, art. 86 (adesão a ata de registro de preços)'],
            ['L10520_ART1', 'L10520', 'Lei 10.520/2002, art. 1º (pregão)'],
            ['L8666_ART24_II', 'L8666', 'Lei 8.666/1993, art. 24, II (dispensa por valor)'],
            ['L8666_ART24_IV', 'L8666', 'Lei 8.666/1993, art. 24, IV (dispensa emergencial)'],
            ['L8666_ART25', 'L8666', 'Lei 8.666/1993, art. 25 (inexigibilidade)'],
            ['L11947_ART14', 'L11947', 'Lei 11.947/2009, art. 14 (agricultura familiar no PNAE)'],
        ];
        foreach ($amparos as [$c, $r, $d]) {
            DB::table('amparos_legais')->updateOrInsert(['codigo' => $c], [
                'regime_legal_id' => $reg($r), 'descricao' => $d, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        $modalidades = [
            ['PREGAO_14133', 'L14133', 'Pregão'], ['CONCORRENCIA_14133', 'L14133', 'Concorrência'],
            ['CONCURSO_14133', 'L14133', 'Concurso'], ['LEILAO_14133', 'L14133', 'Leilão'],
            ['DIALOGO_14133', 'L14133', 'Diálogo competitivo'], ['PREGAO_10520', 'L10520', 'Pregão (Lei 10.520)'],
            ['CONCORRENCIA_8666', 'L8666', 'Concorrência (Lei 8.666)'], ['TOMADA_8666', 'L8666', 'Tomada de preços'],
            ['CONVITE_8666', 'L8666', 'Convite'],
        ];
        foreach ($modalidades as [$c, $r, $n]) {
            DB::table('classificacoes_modalidades')->updateOrInsert(['codigo' => $c], [
                'regime_legal_id' => $reg($r), 'nome' => $n, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        // Equivalência entre modalidades dos regimes antigos e da Lei 14.133 (séries históricas comparáveis).
        $modId = fn (string $c) => DB::table('classificacoes_modalidades')->where('codigo', $c)->value('id');
        $equivalencias = [
            ['PREGAO_10520', 'PREGAO_14133', 'Pregão da Lei 10.520/2002 equivale ao pregão da Lei 14.133/2021.'],
            ['CONCORRENCIA_8666', 'CONCORRENCIA_14133', 'Concorrência da Lei 8.666/1993 equivale à concorrência da Lei 14.133/2021.'],
            ['TOMADA_8666', 'CONCORRENCIA_14133', 'Tomada de preços foi extinta; a Lei 14.133/2021 usa concorrência para esses objetos.'],
            ['CONVITE_8666', 'CONCORRENCIA_14133', 'Convite foi extinto; a comparação mais próxima é a concorrência (ou dispensa, por valor).'],
        ];
        foreach ($equivalencias as [$o, $e, $obs]) {
            DB::table('equivalencias_regimes')->updateOrInsert(
                ['tipo' => 'MODALIDADE', 'origem_id' => $modId($o), 'equivalente_id' => $modId($e)],
                ['observacao' => $obs, 'created_at' => $agora, 'updated_at' => $agora],
            );
        }

        // Sistemática > forma de aquisição. com_disputa = false fica fora do % de economia por padrão.
        $sistematicas = [
            ['PREGAO', 'Pregão', 'LICITACAO', true, [['PREGAO_ELETRONICO', 'Pregão eletrônico'], ['PREGAO_PRESENCIAL', 'Pregão presencial']]],
            ['CONCORRENCIA', 'Concorrência', 'LICITACAO', true, [['CONCORRENCIA_ELETRONICA', 'Concorrência eletrônica'], ['CONCORRENCIA_PRESENCIAL', 'Concorrência presencial']]],
            ['CONCURSO', 'Concurso', 'LICITACAO', true, [['CONCURSO', 'Concurso']]],
            ['LEILAO', 'Leilão', 'LICITACAO', true, [['LEILAO', 'Leilão']]],
            ['DIALOGO', 'Diálogo competitivo', 'LICITACAO', true, [['DIALOGO', 'Diálogo competitivo']]],
            ['DISPENSA', 'Dispensa de licitação', 'CONTRATACAO_DIRETA', true, [['DISPENSA_ELETRONICA', 'Dispensa eletrônica'], ['DISPENSA_VALOR', 'Dispensa por valor'], ['DISPENSA_EMERGENCIAL', 'Dispensa emergencial']]],
            ['INEXIGIBILIDADE', 'Inexigibilidade', 'CONTRATACAO_DIRETA', false, [['INEXIGIBILIDADE', 'Inexigibilidade']]],
            ['CHAMADA_PNAE', 'Chamada Pública PNAE', 'CHAMADA_PUBLICA', false, [['CHAMADA_PNAE', 'Chamada pública da agricultura familiar (PNAE)']]],
            ['CREDENCIAMENTO', 'Credenciamento', 'PROCEDIMENTO_AUXILIAR', false, [['CREDENCIAMENTO', 'Credenciamento']]],
            ['ADESAO_ATA', 'Adesão a Ata de Registro de Preços', 'PROCEDIMENTO_AUXILIAR', false, [['ADESAO_ATA', 'Adesão (carona)']]],
            ['OUTRA', 'Outras classificações históricas', 'OUTRO', false, [['OUTRA', 'Outra']]],
        ];
        foreach ($sistematicas as $i => [$c, $n, $t, $disp, $formas]) {
            DB::table('sistematicas_aquisicao')->updateOrInsert(['codigo' => $c], [
                'nome' => $n, 'tipo' => $t, 'com_disputa' => $disp, 'ordem' => $i + 1, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
            $sid = DB::table('sistematicas_aquisicao')->where('codigo', $c)->value('id');
            foreach ($formas as [$fc, $fn]) {
                DB::table('formas_aquisicao')->updateOrInsert(['codigo' => $fc], [
                    'sistematica_id' => $sid, 'nome' => $fn, 'created_at' => $agora, 'updated_at' => $agora,
                ]);
            }
        }

        $naturezas = [
            ['CONSUMO', 'Material de consumo'], ['PERMANENTE', 'Material permanente'], ['EQUIPAMENTOS', 'Equipamentos'],
            ['SERVICOS', 'Serviços'], ['OBRAS', 'Obras'], ['ENGENHARIA', 'Serviços de engenharia'],
            ['TI', 'Tecnologia da Informação'], ['ALIMENTOS', 'Gêneros alimentícios'],
            ['SAUDE', 'Medicamentos e materiais hospitalares'], ['OUTROS', 'Outros'],
        ];
        foreach ($naturezas as $i => [$c, $n]) {
            DB::table('classificacoes_natureza')->updateOrInsert(['codigo' => $c], [
                'nome' => $n, 'ordem' => $i + 1, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        // Natureza da despesa > elemento > subelemento (amostra da classificação orçamentária federal/estadual)
        $despesa = [
            '3.3.90' => ['Outras despesas correntes - aplicações diretas', [
                '3.3.90.30' => ['Material de consumo', [
                    '3.3.90.30.07' => 'Gêneros de alimentação', '3.3.90.30.09' => 'Material farmacológico',
                    '3.3.90.30.16' => 'Material de expediente', '3.3.90.30.17' => 'Material de processamento de dados',
                    '3.3.90.30.36' => 'Material hospitalar', '3.3.90.30.22' => 'Material de limpeza e produtos de higienização',
                ]],
                '3.3.90.39' => ['Outros serviços de terceiros - pessoa jurídica', [
                    '3.3.90.39.16' => 'Manutenção e conservação de bens imóveis', '3.3.90.39.79' => 'Serviço de apoio administrativo',
                ]],
                '3.3.90.40' => ['Serviços de tecnologia da informação e comunicação - PJ', [
                    '3.3.90.40.06' => 'Locação de equipamentos de TIC', '3.3.90.40.11' => 'Suporte de infraestrutura de TIC',
                ]],
                '3.3.90.37' => ['Locação de mão de obra', ['3.3.90.37.01' => 'Apoio administrativo, técnico e operacional']],
            ]],
            '4.4.90' => ['Investimentos - aplicações diretas', [
                '4.4.90.52' => ['Equipamentos e material permanente', [
                    '4.4.90.52.35' => 'Equipamentos de processamento de dados', '4.4.90.52.42' => 'Mobiliário em geral',
                    '4.4.90.52.08' => 'Aparelhos e equipamentos médico-hospitalares',
                ]],
                '4.4.90.51' => ['Obras e instalações', ['4.4.90.51.91' => 'Obras em andamento']],
            ]],
        ];
        foreach ($despesa as $nc => [$nn, $elementos]) {
            DB::table('naturezas_despesa')->updateOrInsert(['codigo' => $nc], ['nome' => $nn, 'created_at' => $agora, 'updated_at' => $agora]);
            $nid = DB::table('naturezas_despesa')->where('codigo', $nc)->value('id');
            foreach ($elementos as $ec => [$en, $subs]) {
                DB::table('elementos_despesa')->updateOrInsert(['codigo' => $ec], [
                    'natureza_despesa_id' => $nid, 'nome' => $en, 'created_at' => $agora, 'updated_at' => $agora,
                ]);
                $eid = DB::table('elementos_despesa')->where('codigo', $ec)->value('id');
                foreach ($subs as $sc => $sn) {
                    DB::table('subelementos_despesa')->updateOrInsert(['codigo' => $sc], [
                        'elemento_despesa_id' => $eid, 'nome' => $sn, 'created_at' => $agora, 'updated_at' => $agora,
                    ]);
                }
            }
        }

        foreach ([['UN', 'Unidade'], ['KG', 'Quilograma'], ['CX', 'Caixa'], ['PCT', 'Pacote'], ['L', 'Litro'],
            ['RESMA', 'Resma'], ['MES', 'Mês'], ['SERV', 'Serviço'], ['M2', 'Metro quadrado'], ['FR', 'Frasco']] as [$s, $n]) {
            DB::table('unidades_medida')->updateOrInsert(['sigla' => $s], ['nome' => $n, 'created_at' => $agora, 'updated_at' => $agora]);
        }

        foreach ([['CATMAT', 'Catálogo de Materiais'], ['CATSER', 'Catálogo de Serviços']] as [$c, $n]) {
            DB::table('catalogos')->updateOrInsert(['codigo' => $c], ['nome' => $n, 'created_at' => $agora, 'updated_at' => $agora]);
        }

        foreach ([
            ['PNCP', 'Portal Nacional de Contratações Públicas'],
            ['COMPRASGOV', 'Compras.gov.br'],
            ['FINANCEIRO_RR', 'Sistema de execução orçamentária e financeira do Estado'],
        ] as [$c, $n]) {
            DB::table('integracoes')->updateOrInsert(['codigo' => $c], ['nome' => $n, 'ativa' => false, 'created_at' => $agora, 'updated_at' => $agora]);
        }

        foreach ([
            ['economia.base_comparacao', 'contratado', 'Valor comparado ao estimado no cálculo da economia: adjudicado, homologado ou contratado'],
            ['economia.incluir_sem_disputa', '0', 'Incluir procedimentos sem disputa de preço (PNAE, credenciamento, inexigibilidade) no % de economia'],
            ['contratos.dias_alerta_vencimento', '90', 'Dias para considerar um contrato próximo do vencimento'],
            ['atas.dias_alerta_vencimento', '90', 'Dias para considerar uma ata próxima do vencimento'],
            ['precos.minimo_observacoes', '3', 'Mínimo de preços do mesmo item, unidade de fornecimento e ano para formar a referência estadual'],
            ['pnae.percentual_minimo_af', '30', 'Percentual mínimo dos recursos do PNAE a aplicar na agricultura familiar (Lei nº 11.947/2009, art. 14). Ajuste se a legislação mudar'],
            ['atas.utilizacao_baixa', '30', 'Percentual de utilização abaixo do qual uma ata é sinalizada como de baixa utilização'],
            ['atas.utilizacao_alta', '80', 'Percentual de utilização a partir do qual uma ata é sinalizada como de alta utilização'],
            ['precos.janela_meses', '24', 'Janela, em meses até a data de referência, das compras usadas como referência de preço'],
            ['precos.alerta_mediana_pct', '30', 'Desvio percentual de um preço em relação à mediana a partir do qual ele é sinalizado para análise'],
            ['precos.alerta_cv_pct', '35', 'Coeficiente de variação (%) a partir do qual a dispersão dos preços é sinalizada para análise'],
            ['precos.alerta_aumento_pct', '25', 'Aumento da mediana de um ano para o seguinte (%) a partir do qual o aumento é sinalizado para análise'],
            ['compras.recorrencia_minima', '3', 'Número mínimo de processos com o mesmo item e unidade para considerar a compra recorrente'],
            ['fornecedores.limite_concentracao', '30', 'Percentual do valor contratado de uma unidade, por um único fornecedor, a partir do qual a concentração é sinalizada para análise'],
        ] as [$k, $v, $d]) {
            DB::table('configuracoes')->updateOrInsert(['chave' => $k], ['valor' => $v, 'descricao' => $d, 'created_at' => $agora, 'updated_at' => $agora]);
        }
    }
}
