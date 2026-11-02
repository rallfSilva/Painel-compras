<?php

namespace App\Services\Ia;

use App\Services\Compras\FornecedoresService;
use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Inteligencia\AnaliseTotalService;
use App\Services\Inteligencia\AquisicoesRpService;
use App\Services\Inteligencia\PrecosService;
use App\Services\Planejamento\ContratosService;
use App\Services\Planejamento\PcaService;

/**
 * Análise automatizada, alertas e recomendações gerenciais (Etapa 6). Regras explícitas sobre os
 * indicadores do sistema, com os parâmetros da Administração: cada alerta diz quantos registros
 * atende, por que foi gerado, o que se recomenda avaliar e em que módulo ver os registros.
 * As recomendações são de gestão; nenhuma afirma irregularidade.
 */
final class AlertasService
{
    public function __construct(
        private readonly AquisicoesRpService $atas,
        private readonly ContratosService $contratos,
        private readonly PrecosService $precos,
        private readonly AnaliseTotalService $analise,
        private readonly FornecedoresService $fornecedores,
        private readonly PcaService $pca,
        private readonly Configuracoes $config,
    ) {}

    public function gerar(FiltrosAplicados $f): array
    {
        $alertas = [];

        $rp = $this->atas->painel($f, ['ata_id' => null, 'item_id' => null, 'unidade_id' => null]);
        $sinal = collect($rp['sinalizadores'])->keyBy('codigo');
        $dias = $rp['parametros']['dias_alerta_vencimento'];
        if ($n = $sinal['VENCIMENTO_PROXIMO']['quantidade'] ?? 0) {
            $alertas[] = self::alerta('atas_vencendo', 'alta', "Atas vencendo em até $dias dias", $n,
                "$n atas de registro de preços vigentes vencem dentro do prazo de alerta.",
                'Verificar a demanda remanescente e iniciar, se necessário, nova licitação para registro de preços antes do vencimento, para não interromper o abastecimento.',
                'aquisicoes-rp');
        }
        if ($n = $sinal['ACIMA_100']['quantidade'] ?? 0) {
            $alertas[] = self::alerta('atas_acima_100', 'alta', 'Atas com item acima de 100% de utilização', $n,
                "$n atas têm pelo menos um item utilizado acima da quantidade registrada.",
                'Conferir as utilizações à luz dos limites de adesão e de acréscimo da Lei nº 14.133/2021 e do regulamento estadual, e corrigir lançamentos, se for o caso.',
                'aquisicoes-rp');
        }
        if ($n = $sinal['BAIXA_UTILIZACAO']['quantidade'] ?? 0) {
            $alertas[] = self::alerta('atas_baixa_utilizacao', 'media', "Atas com utilização abaixo de {$rp['parametros']['utilizacao_baixa']}%", $n,
                "$n atas tiveram baixa utilização do total registrado.",
                'Rever as estimativas de quantidade dos órgãos participantes nas próximas licitações para registro de preços.',
                'aquisicoes-rp');
        }

        $ctr = $this->contratos->painel($f);
        $prazo = $this->config->int('contratos.dias_alerta_vencimento', 90);
        $vencendo = collect($ctr['vencendo'])->firstWhere('dias', $prazo)['quantidade'] ?? collect($ctr['vencendo'])->last()['quantidade'] ?? 0;
        if ($vencendo) {
            $alertas[] = self::alerta('contratos_vencendo', 'alta', "Contratos vencendo em até $prazo dias", $vencendo,
                "$vencendo contratos vigentes chegam ao fim da vigência no prazo de alerta.",
                'Avaliar com os gestores a prorrogação, quando cabível, ou a nova contratação, com antecedência suficiente.',
                'contratos');
        }
        if ($n = $ctr['indicadores']['sinalizados'] ?? 0) {
            $alertas[] = self::alerta('contratos_aditivos', 'media', 'Contratos com acréscimos próximos do limite legal', $n,
                "$n contratos têm acréscimos por aditivo próximos ou acima do limite configurado ({$ctr['parametros']['limite']}%).",
                'Acompanhar novos aditivos desses contratos e verificar o enquadramento nos limites de acréscimo.',
                'contratos');
        }

        $pr = $this->precos->painel($f);
        if ($n = $pr['resumo']['dispersao_alta']) {
            $alertas[] = self::alerta('precos_dispersao', 'media', 'Itens com alta variação de preço', $n,
                "$n itens têm coeficiente de variação do preço unitário igual ou acima de {$pr['parametros']['cv']}%.",
                'Usar a mediana e a pesquisa de preços atualizada como referência nas próximas estimativas desses itens.',
                'analise-precos');
        }

        $rec = collect($this->analise->recorrentes($f, 300)['itens'])->filter(fn ($i) => $i['orgaos'] >= 2 && ($i['percentual_rp'] ?? 0) < 50);
        if ($n = $rec->count()) {
            $alertas[] = self::alerta('planejamento_conjunto', 'media', 'Compras recorrentes que podem ser planejadas em conjunto', $n,
                "$n itens são comprados repetidamente por dois ou mais órgãos, com menos da metade das compras por registro de preços.",
                'Avaliar uma licitação conjunta ou para registro de preços, com a SELC como gerenciadora, para ganho de escala.',
                'analise-total');
        }

        $conc = $this->fornecedores->concentracao($f);
        if ($n = count($conc['sinalizadores'])) {
            $alertas[] = self::alerta('concentracao_fornecedor', 'baixa', 'Unidades com concentração em um fornecedor', $n,
                "Em $n unidades compradoras, um único fornecedor responde por mais de {$conc['limite_sinalizacao']}% do valor contratado.",
                'Verificar se o mercado permite ampliar a competição (divulgação, parcelamento do objeto, novos fornecedores).',
                'fornecedores');
        }

        $pca = $this->pca->painel($f)['indicadores'];
        if ($n = $pca['atrasadas'] ?? 0) {
            $alertas[] = self::alerta('pca_atrasadas', 'media', 'Demandas do PCA atrasadas', $n,
                "$n demandas do Plano de Contratações Anual passaram da data prevista sem conclusão.",
                'Revisar o cronograma com as unidades demandantes e atualizar o PCA, se preciso.',
                'pca');
        }

        $ordem = ['alta' => 0, 'media' => 1, 'baixa' => 2];
        usort($alertas, fn ($a, $b) => [$ordem[$a['severidade']], -$a['quantidade']] <=> [$ordem[$b['severidade']], -$b['quantidade']]);

        return [
            'gerado_em' => now()->toIso8601String(),
            'periodo' => $f->descricaoPeriodo(),
            'alertas' => $alertas,
            'aviso' => ConsultasIa::AVISO_JURIDICO,
        ];
    }

    private static function alerta(string $codigo, string $severidade, string $titulo, int $quantidade, string $descricao, string $recomendacao, string $modulo): array
    {
        return compact('codigo', 'severidade', 'titulo', 'quantidade', 'descricao', 'recomendacao', 'modulo');
    }
}
