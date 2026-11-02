<?php

namespace App\Services\Ia;

use App\Services\Compras\ComprasTotaisService;
use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Indicadores\IndicadorService;
use App\Services\Indicadores\OpcoesCalculo;
use App\Services\Inteligencia\AnaliseTotalService;
use App\Services\Inteligencia\AquisicoesRpService;
use App\Services\Inteligencia\MpeService;
use App\Services\Inteligencia\PrecosService;
use App\Services\Planejamento\ContratosService;

/**
 * Execução das consultas do catálogo, sempre pelos serviços de leitura do sistema e com os filtros do
 * usuário (inclusive a restrição por órgão). O texto da resposta é montado aqui, a partir dos números
 * retornados: sem dados, a resposta diz que não há dados, e nunca estima o que não foi registrado.
 *
 * Natureza de cada indicador: "registrado" (valor gravado nos processos, atas e contratos), "estimativa"
 * (derivado do valor estimado da contratação) ou "sinalizacao" (alerta para análise humana).
 */
final class ConsultasIa
{
    public const AVISO_JURIDICO = 'As informações são de apoio à gestão. Sinalizações não constituem conclusão jurídica sobre irregularidade e pedem análise técnica.';

    public function __construct(
        private readonly ComprasTotaisService $compras,
        private readonly IndicadorService $indicadores,
        private readonly AnaliseTotalService $analise,
        private readonly AquisicoesRpService $atas,
        private readonly MpeService $mpe,
        private readonly PrecosService $precos,
        private readonly ContratosService $contratos,
        private readonly Configuracoes $config,
    ) {}

    /**
     * @param  array<string, mixed>  $p  parâmetros interpretados
     * @param  string  $recorte  descrição do recorte vinda da pergunta ("de EQUIPAMENTOS DE INFORMÁTICA")
     */
    public function executar(string $consulta, FiltrosAplicados $f, array $p, string $recorte, bool $verDadosPessoais): array
    {
        return match ($consulta) {
            'total_comprado' => $this->totalComprado($f, $recorte),
            'ranking_orgaos' => $this->ranking($f, $p, 10, 'o.id', 'COALESCE(o.sigla, o.nome)', 'unidade', 'o', 'Órgão', 'o órgão que mais comprou', 'compras-totais'),
            'ranking_itens' => $this->ranking($f, $p, 20, 'pi.item_catalogo_id', 'COALESCE(ic.nome_resumido, ic.descricao, pi.descricao)', 'item', 'i', 'Item', 'o item com maior valor contratado', 'itens-adjudicados'),
            'ranking_fornecedores' => $this->ranking($f, $p, 10, 'fo.id', 'fo.razao_social', 'fornecedor', 'fo', 'Fornecedor', 'o fornecedor que mais vendeu', 'fornecedores'),
            'municipios_volume' => $this->ranking($f, $p, 10, 'uc.municipio_id', 'mc.nome', 'comprador_local', 'm', 'Município', 'o município com maior volume de compras', 'compras-totais'),
            'economia_media' => $this->economia($f),
            'orgaos_mesmos_materiais' => $this->mesmosMateriais($f, $p),
            'compras_conjuntas' => $this->comprasConjuntas($f, $p),
            'variacao_precos' => $this->variacaoPrecos($f, $p),
            'atas_vencendo' => $this->atasVencendo($f, $p),
            'atas_acima_100' => $this->atasAcima100($f, $p),
            'participacao_mpe' => $this->participacaoMpe($f, $p),
            'agricultura_familiar' => $this->agriculturaFamiliar($f),
            'contratos_vencendo' => $this->contratosVencendo($f, $p, $verDadosPessoais),
        };
    }

    private function opcoes(): OpcoesCalculo
    {
        return new OpcoesCalculo($this->config->baseEconomia(), $this->config->incluirSemDisputa(), $this->config->int('contratos.dias_alerta_vencimento', 90));
    }

    private function totalComprado(FiltrosAplicados $f, string $recorte): array
    {
        $r = $this->indicadores->calcular($f, $this->opcoes());
        if (! $r['processos'] || $r['total_contratado'] === null) {
            return self::semDados('Não há valor contratado registrado'.($recorte ? " $recorte" : '').' no período e nos filtros aplicados.', 'compras-totais');
        }
        $anos = $this->compras->agrupar($f, 'p.ano', 'p.ano', 'tempo', 'a', 30);

        return [
            'resposta' => sprintf('Foram contratados %s%s, em %s processos com valor contratado e %s fornecedores.', self::moeda($r['total_contratado']), $recorte ? " $recorte" : '',
                self::numero(array_sum(array_column($anos, 'processos'))), self::numero($r['fornecedores'])),
            'indicadores' => [
                self::ind('total_contratado', 'Total contratado', $r['total_contratado'], 'moeda', 'registrado'),
                self::ind('total_estimado', 'Total estimado', $r['total_estimado'], 'moeda', 'estimativa'),
                self::ind('processos', 'Processos com valor contratado', array_sum(array_column($anos, 'processos')), 'numero', 'registrado'),
                self::ind('fornecedores', 'Fornecedores', $r['fornecedores'], 'numero', 'registrado'),
            ],
            'tabela' => self::tabela([['ano', 'Ano', 'texto'], ['contratado', 'Contratado', 'moeda'], ['percentual', '% do total', 'percentual'], ['processos', 'Processos', 'numero']],
                collect($anos)->sortBy('nome')->map(fn ($l) => ['ano' => (string) $l['nome'], 'contratado' => $l['contratado'], 'percentual' => $l['percentual'], 'processos' => $l['processos'],
                    '_abrir' => $l['chave'] ? ['filtro' => 'tempo', 'chave' => $l['chave'], 'rotulo' => (string) $l['nome']] : null])->values()->all()),
            'fonte' => 'Itens dos processos de compra (valor total contratado).',
            'modulo' => 'compras-totais',
        ];
    }

    private function ranking(FiltrosAplicados $f, array $p, int $padrao, string $col, string $nome, string $filtro, string $prefixo, string $rotulo, string $frase, string $modulo): array
    {
        $limite = (int) ($p['limite'] ?? $padrao);
        $linhas = array_values(array_filter($this->compras->agrupar($f, $col, $nome, $filtro, $prefixo, $limite), fn ($l) => empty($l['agrupado'])));
        if (! $linhas) {
            return self::semDados('Não há valor contratado registrado no período e nos filtros aplicados.', $modulo);
        }
        $total = array_sum(array_column($this->compras->agrupar($f, $col, $nome, $filtro, $prefixo, 1), 'contratado'));
        $l1 = $linhas[0];

        return [
            'resposta' => sprintf('%s foi %s, com %s (%s do total) em %s processos.', ucfirst($frase), $l1['nome'] === 'Não informado' ? 'não informado no cadastro' : '“'.$l1['nome'].'”',
                self::moeda($l1['contratado']), self::pct($l1['percentual']), self::numero($l1['processos'])),
            'indicadores' => [
                self::ind('total_contratado', 'Total contratado no recorte', $total, 'moeda', 'registrado'),
                self::ind('participacao_primeiro', 'Participação do 1º colocado', $l1['percentual'], 'percentual', 'registrado'),
            ],
            'tabela' => self::tabela([['posicao', 'Nº', 'numero'], ['nome', $rotulo, 'texto'], ['contratado', 'Contratado', 'moeda'], ['percentual', '% do total', 'percentual'], ['processos', 'Processos', 'numero']],
                collect($linhas)->values()->map(fn ($l, $i) => ['posicao' => $i + 1, 'nome' => $l['nome'], 'contratado' => $l['contratado'], 'percentual' => $l['percentual'], 'processos' => $l['processos'],
                    '_abrir' => $l['chave'] ? ['filtro' => $l['filtro'], 'chave' => $l['chave'], 'rotulo' => $l['nome']] : null])->all()),
            'fonte' => 'Itens dos processos de compra (valor total contratado).',
            'modulo' => $modulo,
        ];
    }

    private function economia(FiltrosAplicados $f): array
    {
        $o = $this->opcoes();
        $r = $this->indicadores->calcular($f, $o);
        if ($r['economia'] === null || $r['percentual_economia'] === null) {
            return self::semDados('Não há itens com valor estimado e valor de referência suficientes para calcular a economia no recorte.', 'compras-totais');
        }

        return [
            'resposta' => sprintf('A economia foi de %s, %s do valor estimado dos itens comparáveis (base: valor %s%s).', self::moeda($r['economia']), self::pct($r['percentual_economia']),
                $o->baseEconomia, $o->incluirSemDisputa ? ', incluindo procedimentos sem disputa' : ', sem procedimentos sem disputa de preço'),
            'indicadores' => [
                self::ind('economia', 'Economia', $r['economia'], 'moeda', 'estimativa'),
                self::ind('percentual_economia', '% de economia', $r['percentual_economia'], 'percentual', 'estimativa'),
                self::ind('total_estimado', 'Total estimado', $r['total_estimado'], 'moeda', 'estimativa'),
                self::ind('total_contratado', 'Total contratado', $r['total_contratado'], 'moeda', 'registrado'),
            ],
            'tabela' => null,
            'fonte' => 'Itens dos processos de compra: valor estimado comparado ao valor de referência configurado.',
            'modulo' => 'compras-totais',
            'avisos' => ['A economia depende do valor estimado informado em cada processo; é um indicador derivado, não um valor efetivamente pago.'],
        ];
    }

    private function mesmosMateriais(FiltrosAplicados $f, array $p): array
    {
        $r = $this->analise->recorrentes($f, 300);
        $itens = collect($r['itens'])->filter(fn ($i) => $i['orgaos'] >= 2)->sortByDesc(fn ($i) => [$i['orgaos'], $i['processos']])->values()->take((int) ($p['limite'] ?? 20));
        if ($itens->isEmpty()) {
            return self::semDados('Nenhum item do catálogo foi comprado por mais de um órgão no recorte.', 'analise-total');
        }
        $i1 = $itens->first();

        return [
            'resposta' => sprintf('%s itens foram comprados por mais de um órgão. O mais compartilhado é “%s”, comprado por %s órgãos em %s processos.',
                self::numero(collect($r['itens'])->where('orgaos', '>=', 2)->count()), $i1['nome'], $i1['orgaos'], $i1['processos']),
            'indicadores' => [self::ind('itens_compartilhados', 'Itens comprados por 2 ou mais órgãos', collect($r['itens'])->where('orgaos', '>=', 2)->count(), 'numero', 'registrado')],
            'tabela' => self::tabelaItensRecorrentes($itens->all()),
            'fonte' => "Itens do catálogo comprados em pelo menos {$r['minimo_processos']} processos (parâmetro compras.recorrencia_minima).",
            'modulo' => 'analise-total',
        ];
    }

    private function comprasConjuntas(FiltrosAplicados $f, array $p): array
    {
        $r = $this->analise->recorrentes($f, 300);
        $cand = collect($r['itens'])->filter(fn ($i) => $i['orgaos'] >= 2)
            ->sortByDesc(fn ($i) => [($i['percentual_rp'] ?? 0) < 50 ? 1 : 0, $i['orgaos'], $i['processos']])->values();
        if ($cand->isEmpty()) {
            return self::semDados('Não há itens comprados repetidamente por mais de um órgão no recorte.', 'analise-total');
        }
        $foraRp = $cand->filter(fn ($i) => ($i['percentual_rp'] ?? 0) < 50)->count();

        return [
            'resposta' => sprintf('%s itens são comprados de forma recorrente por dois ou mais órgãos; em %s deles, menos da metade das compras usou registro de preços. São candidatos a planejamento conjunto ou a uma ata de registro de preços.',
                self::numero($cand->count()), self::numero($foraRp)),
            'indicadores' => [
                self::ind('candidatos', 'Itens candidatos', $cand->count(), 'numero', 'sinalizacao'),
                self::ind('fora_rp', 'Com menos de 50% em RP', $foraRp, 'numero', 'sinalizacao'),
            ],
            'tabela' => self::tabelaItensRecorrentes($cand->take((int) ($p['limite'] ?? 20))->all()),
            'fonte' => "Itens do catálogo comprados em pelo menos {$r['minimo_processos']} processos por mais de um órgão.",
            'modulo' => 'analise-total',
            'natureza' => 'sinalizacao',
            'avisos' => ['Recorrência indica oportunidade de planejamento; não é conclusão sobre fracionamento de despesa.'],
        ];
    }

    private static function tabelaItensRecorrentes(array $itens): array
    {
        return self::tabela([['nome', 'Item', 'texto'], ['unidade', 'Unid.', 'texto'], ['orgaos', 'Órgãos', 'numero'], ['processos', 'Processos', 'numero'],
            ['percentual_rp', '% em RP', 'percentual'], ['contratado', 'Contratado', 'moeda']],
            array_map(fn ($i) => ['nome' => $i['nome'], 'unidade' => $i['unidade'], 'orgaos' => $i['orgaos'], 'processos' => $i['processos'],
                'percentual_rp' => $i['percentual_rp'], 'contratado' => $i['contratado'], '_abrir' => ['filtro' => 'item', 'chave' => $i['chave'], 'rotulo' => $i['nome']]], $itens));
    }

    private function variacaoPrecos(FiltrosAplicados $f, array $p): array
    {
        $d = $this->precos->painel($f);
        $itens = collect($d['maior_dispersao'])->take((int) ($p['limite'] ?? 15))->values();
        if ($itens->isEmpty()) {
            return self::semDados(sprintf('Não há itens com pelo menos %d preços de referência no recorte para comparar.', $d['parametros']['minimo']), 'analise-precos');
        }
        $i1 = $itens->first();

        return [
            'resposta' => sprintf('O item com maior variação de preço é “%s” (%s): preços de %s a %s, coeficiente de variação de %s em %s referências. %s',
                $i1['descricao'], $i1['unidade'] ?? 'unidade não informada', self::moeda($i1['minimo']), self::moeda($i1['maximo']), self::pct($i1['cv']), $i1['n'],
                $d['resumo']['dispersao_alta'] ? self::numero($d['resumo']['dispersao_alta']).sprintf(' itens atingem o limite de dispersão alta (CV ≥ %s%%).', $d['parametros']['cv'])
                    : sprintf('Nenhum item atinge o limite de dispersão alta (CV ≥ %s%%).', $d['parametros']['cv'])),
            'indicadores' => [
                self::ind('itens_comparaveis', 'Itens comparáveis', $d['resumo']['itens_comparaveis'], 'numero', 'registrado'),
                self::ind('dispersao_alta', 'Itens com dispersão alta', $d['resumo']['dispersao_alta'], 'numero', 'sinalizacao'),
            ],
            'tabela' => self::tabela([['descricao', 'Item', 'texto'], ['unidade', 'Unid.', 'texto'], ['n', 'Referências', 'numero'], ['minimo', 'Mínimo', 'unitario'],
                ['mediana', 'Mediana', 'unitario'], ['maximo', 'Máximo', 'unitario'], ['cv', 'CV', 'percentual']],
                $itens->map(fn ($i) => ['descricao' => $i['descricao'], 'unidade' => $i['unidade'], 'n' => $i['n'], 'minimo' => $i['minimo'], 'mediana' => $i['mediana'],
                    'maximo' => $i['maximo'], 'cv' => $i['cv'], '_abrir' => ['filtro' => 'item', 'chave' => 'i:'.$i['item_id'], 'rotulo' => $i['descricao']]])->all()),
            'fonte' => sprintf('Preço unitário adjudicado dos itens, janela de %s meses, mínimo de %d referências por item e unidade.', $d['parametros']['janela_usada'] ?: 'todos os', $d['parametros']['minimo']),
            'modulo' => 'analise-precos',
            'natureza' => 'sinalizacao',
            'avisos' => [PrecosService::AVISO],
        ];
    }

    private function porAta(FiltrosAplicados $f): array
    {
        return $this->atas->painel($f, ['ata_id' => null, 'item_id' => null, 'unidade_id' => null]);
    }

    private function atasVencendo(FiltrosAplicados $f, array $p): array
    {
        $d = $this->porAta($f);
        $dias = (int) ($p['dias'] ?? $d['parametros']['dias_alerta_vencimento']);
        $hoje = $f->hoje()->toDateString();
        $ate = $f->hoje()->addDays($dias)->toDateString();
        $atas = collect($d['por_ata'])->filter(fn ($a) => $a['situacao'] === 'VIGENTE' && $a['validade'] >= $hoje && $a['validade'] <= $ate)->sortBy('validade')->values();
        if ($atas->isEmpty()) {
            return self::semDados("Nenhuma ata vigente vence nos próximos $dias dias no recorte.", 'aquisicoes-rp') + ['natureza' => 'sinalizacao'];
        }

        return [
            'resposta' => sprintf('%s atas vigentes vencem nos próximos %d dias. A primeira é a %s, com validade em %s e %s utilizado.',
                self::numero($atas->count()), $dias, $atas[0]['numero'], self::data($atas[0]['validade']), self::pct($atas[0]['percentual_utilizado'])),
            'indicadores' => [self::ind('atas_vencendo', "Atas vencendo em até $dias dias", $atas->count(), 'numero', 'sinalizacao')],
            'tabela' => self::tabelaAtas($atas->take((int) ($p['limite'] ?? 30))->all()),
            'fonte' => 'Atas de registro de preços: vigência e utilização dos itens.',
            'modulo' => 'aquisicoes-rp',
            'natureza' => 'sinalizacao',
        ];
    }

    private function atasAcima100(FiltrosAplicados $f, array $p): array
    {
        $atas = collect($this->porAta($f)['por_ata'])->filter(fn ($a) => $a['itens_acima_100'] > 0)->sortByDesc('itens_acima_100')->values();
        if ($atas->isEmpty()) {
            return self::semDados('Nenhuma ata tem item utilizado acima da quantidade registrada no recorte.', 'aquisicoes-rp') + ['natureza' => 'sinalizacao'];
        }

        return [
            'resposta' => sprintf('%s atas têm pelo menos um item utilizado acima de 100%% da quantidade registrada. A que tem mais itens nessa situação é a %s (%s itens).',
                self::numero($atas->count()), $atas[0]['numero'], $atas[0]['itens_acima_100']),
            'indicadores' => [self::ind('atas_acima_100', 'Atas com item acima de 100%', $atas->count(), 'numero', 'sinalizacao')],
            'tabela' => self::tabelaAtas($atas->take((int) ($p['limite'] ?? 30))->all(), true),
            'fonte' => 'Atas de registro de preços: quantidade registrada e utilizações por item.',
            'modulo' => 'aquisicoes-rp',
            'natureza' => 'sinalizacao',
            'avisos' => ['Utilização acima do registrado requer análise à luz dos limites de adesão e de acréscimo da Lei nº 14.133/2021 e do regulamento estadual.'],
        ];
    }

    private static function tabelaAtas(array $atas, bool $acima = false): array
    {
        $cols = [['numero', 'Ata', 'texto'], ['gerenciador', 'Gerenciador', 'texto'], ['objeto', 'Objeto', 'texto'], ['validade', 'Validade', 'data'], ['percentual_utilizado', '% utilizado', 'percentual']];
        if ($acima) {
            $cols[] = ['itens_acima_100', 'Itens acima de 100%', 'numero'];
        }

        return self::tabela($cols, array_map(fn ($a) => array_intersect_key($a, array_flip(array_column($cols, 0))), $atas));
    }

    private function participacaoMpe(FiltrosAplicados $f, array $p): array
    {
        $d = $this->mpe->painel($f);
        $ind = $d['indicadores'];
        $orgaos = collect($d['por_orgao'])->filter(fn ($o) => $o['total'] > 0)->sortByDesc('percentual')->values();
        if ($ind['percentual_mpe'] === null || $orgaos->isEmpty()) {
            return self::semDados('Não há valor contratado com porte de fornecedor informado no recorte.', 'compras-mpe');
        }
        $o1 = $orgaos->first();

        return [
            'resposta' => sprintf('As MPE (MEI, ME e EPP) responderam por %s do valor contratado (%s). O órgão com maior participação de MPE é %s, com %s do que contratou.',
                self::pct($ind['percentual_mpe']), self::moeda($ind['valor_mpe']), $o1['nome'], self::pct($o1['percentual'])),
            'indicadores' => [
                self::ind('valor_mpe', 'Contratado de MPE', $ind['valor_mpe'], 'moeda', 'registrado'),
                self::ind('percentual_mpe', '% MPE', $ind['percentual_mpe'], 'percentual', 'registrado'),
                self::ind('fornecedores_mpe', 'Fornecedores MPE', $ind['fornecedores_mpe'], 'numero', 'registrado'),
            ],
            'tabela' => self::tabela([['nome', 'Órgão', 'texto'], ['percentual', '% MPE', 'percentual'], ['contratado', 'Contratado de MPE', 'moeda'], ['total', 'Total contratado', 'moeda'], ['fornecedores_mpe', 'Fornecedores MPE', 'numero']],
                $orgaos->take((int) ($p['limite'] ?? 20))->map(fn ($o) => ['nome' => $o['nome'], 'percentual' => $o['percentual'], 'contratado' => $o['contratado'], 'total' => $o['total'],
                    'fornecedores_mpe' => $o['fornecedores_mpe'], '_abrir' => ['filtro' => 'unidade', 'chave' => $o['chave'], 'rotulo' => $o['nome']]])->all()),
            'fonte' => 'Itens contratados com fornecedor informado, pelo porte do fornecedor. Agricultura familiar e cooperativas ficam em categoria própria.',
            'modulo' => 'compras-mpe',
        ];
    }

    private function agriculturaFamiliar(FiltrosAplicados $f): array
    {
        $ind = $this->mpe->painel($f)['indicadores'];
        if (! $ind['valor_agricultura_familiar']) {
            return self::semDados('Não há valor contratado de agricultores familiares ou cooperativas no recorte.', 'compras-alimentos');
        }

        return [
            'resposta' => sprintf('Foram contratados %s da agricultura familiar (produtores e cooperativas), %s do valor contratado, de %s fornecedores.',
                self::moeda($ind['valor_agricultura_familiar']), self::pct($ind['percentual_agricultura_familiar']), self::numero($ind['fornecedores_agricultura_familiar'])),
            'indicadores' => [
                self::ind('valor_agricultura_familiar', 'Contratado da agricultura familiar', $ind['valor_agricultura_familiar'], 'moeda', 'registrado'),
                self::ind('percentual_agricultura_familiar', '% do contratado', $ind['percentual_agricultura_familiar'], 'percentual', 'registrado'),
                self::ind('fornecedores_agricultura_familiar', 'Fornecedores', $ind['fornecedores_agricultura_familiar'], 'numero', 'registrado'),
            ],
            'tabela' => null,
            'fonte' => 'Itens contratados de fornecedores com porte PAF (produtor da agricultura familiar) ou COOP (cooperativa).',
            'modulo' => 'compras-alimentos',
        ];
    }

    private function contratosVencendo(FiltrosAplicados $f, array $p, bool $ver): array
    {
        $dias = (int) ($p['dias'] ?? $this->config->int('contratos.dias_alerta_vencimento', 90));
        $pag = $this->contratos->listar($f, ['busca' => '', 'situacao' => []], $dias, false, 'vigencia_fim', 'asc', (int) ($p['limite'] ?? 30), 1, $ver);
        if ($pag->total() === 0) {
            return self::semDados("Nenhum contrato vigente vence nos próximos $dias dias no recorte.", 'contratos') + ['natureza' => 'sinalizacao'];
        }
        $c1 = $pag->items()[0];

        return [
            'resposta' => sprintf('%s contratos vigentes vencem nos próximos %d dias. O primeiro é o %s (%s), em %s.', self::numero($pag->total()), $dias, $c1->numero, $c1->orgao, self::data($c1->vigencia_fim)),
            'indicadores' => [self::ind('contratos_vencendo', "Contratos vencendo em até $dias dias", $pag->total(), 'numero', 'sinalizacao')],
            'tabela' => self::tabela([['numero', 'Contrato', 'texto'], ['orgao', 'Órgão', 'texto'], ['fornecedor', 'Fornecedor', 'texto'], ['vigencia_fim', 'Vencimento', 'data'],
                ['dias_para_vencer', 'Dias', 'numero'], ['valor_atualizado', 'Valor atualizado', 'moeda']],
                collect($pag->items())->map(fn ($c) => ['numero' => $c->numero, 'orgao' => $c->orgao, 'fornecedor' => $c->fornecedor, 'vigencia_fim' => $c->vigencia_fim,
                    'dias_para_vencer' => $c->dias_para_vencer, 'valor_atualizado' => $c->valor_atualizado])->all()),
            'fonte' => 'Contratos vigentes: data de fim da vigência.',
            'modulo' => 'contratos',
            'natureza' => 'sinalizacao',
        ];
    }

    // ---- formatação ----

    public static function semDados(string $mensagem, string $modulo): array
    {
        return ['resposta' => $mensagem, 'sem_dados' => true, 'indicadores' => [], 'tabela' => null, 'fonte' => null, 'modulo' => $modulo];
    }

    private static function ind(string $codigo, string $nome, int|float|null $valor, string $formato, string $natureza): array
    {
        return compact('codigo', 'nome', 'valor', 'formato', 'natureza');
    }

    /** @param  list<array{0: string, 1: string, 2: string}>  $colunas */
    private static function tabela(array $colunas, array $linhas): array
    {
        return ['colunas' => array_map(fn ($c) => ['chave' => $c[0], 'rotulo' => $c[1], 'formato' => $c[2]], $colunas), 'linhas' => array_values($linhas)];
    }

    public static function moeda(int|float|null $v): string
    {
        return $v === null ? 'valor não informado' : 'R$ '.number_format((float) $v, 2, ',', '.');
    }

    public static function pct(int|float|null $v): string
    {
        return $v === null ? 'percentual não calculável' : number_format((float) $v, 2, ',', '.').'%';
    }

    public static function numero(int|float|null $v): string
    {
        return number_format((float) $v, 0, ',', '.');
    }

    private static function data(?string $d): string
    {
        return $d ? implode('/', array_reverse(explode('-', substr($d, 0, 10)))) : 'data não informada';
    }
}
