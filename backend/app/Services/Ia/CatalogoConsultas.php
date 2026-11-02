<?php

namespace App\Services\Ia;

/**
 * Catálogo fechado das consultas que o assistente pode executar (seção 32). Cada consulta usa os serviços
 * de leitura do sistema, com os filtros e as permissões do usuário. Nenhuma pergunta gera SQL livre.
 *
 * Os padrões servem ao interpretador por regras: expressões sobre o texto normalizado (minúsculas, sem
 * acentos) e o peso de cada uma. Vence a consulta com maior soma, a partir do mínimo.
 */
final class CatalogoConsultas
{
    public const MINIMO = 3;

    public const CONSULTAS = [
        'total_comprado' => [
            'titulo' => 'Total comprado',
            'descricao' => 'Valor total contratado no recorte, opcionalmente de um tipo de material ou serviço (grupo, classe ou item do catálogo), de um órgão ou de um ano.',
            'exemplo' => 'Quanto o Governo de Roraima comprou de material de informática em 2025?',
            'padroes' => [['/\bquant[oa]s?\b/', 1], ['/\b(compr|gast|adquir|contrat)\w*/', 2], ['/\b(total|valor|volume)\b/', 1]],
        ],
        'ranking_orgaos' => [
            'titulo' => 'Órgãos que mais compraram',
            'descricao' => 'Ranking de órgãos (secretarias) por valor contratado.',
            'exemplo' => 'Qual secretaria realizou mais compras?',
            'padroes' => [['/\b(secretarias?|orgaos?|unidades?)\b/', 2], ['/\b(mais|maior(es)?|ranking)\b/', 1], ['/\b(compr|contrat|gast)\w*/', 1]],
        ],
        'ranking_itens' => [
            'titulo' => 'Itens mais comprados',
            'descricao' => 'Ranking de itens do catálogo por valor contratado.',
            'exemplo' => 'Quais foram os 20 itens mais comprados?',
            'padroes' => [['/\b(itens|item|produtos?|materiais)\b/', 2], ['/\bmais\b.*\b(compr|adquir|contrat)\w*/', 2]],
        ],
        'economia_media' => [
            'titulo' => 'Economia das licitações',
            'descricao' => 'Economia em relação ao valor estimado dos itens comparáveis, em valor e em percentual.',
            'exemplo' => 'Qual foi a economia média das licitações?',
            'padroes' => [['/\beconomi/', 4]],
        ],
        'orgaos_mesmos_materiais' => [
            'titulo' => 'Órgãos que compram os mesmos materiais',
            'descricao' => 'Itens comprados por mais de um órgão, com quantos órgãos e processos.',
            'exemplo' => 'Quais órgãos compraram os mesmos materiais?',
            'padroes' => [['/\b(mesm[oa]s|em comum|iguais|compartilh)/', 4], ['/\b(orgaos?|secretarias?)\b/', 1]],
        ],
        'ranking_fornecedores' => [
            'titulo' => 'Fornecedores que mais venderam',
            'descricao' => 'Ranking de fornecedores por valor contratado.',
            'exemplo' => 'Quais fornecedores mais venderam para o Governo?',
            'padroes' => [['/\bfornecedor|\bempresas?\b/', 3], ['/\b(mais|maior(es)?|ranking)\b/', 1], ['/\b(vend|contrat|forneceram)\w*/', 1]],
        ],
        'variacao_precos' => [
            'titulo' => 'Itens com maior variação de preço',
            'descricao' => 'Itens com maior dispersão do preço unitário adjudicado (coeficiente de variação e amplitude).',
            'exemplo' => 'Quais itens apresentaram maior variação de preço?',
            'padroes' => [['/\b(variac|variar|dispers|oscil|diferenca)\w*/', 3], ['/\bprec/', 2]],
        ],
        'atas_vencendo' => [
            'titulo' => 'Atas próximas do vencimento',
            'descricao' => 'Atas de registro de preços vigentes que vencem dentro do prazo de alerta.',
            'exemplo' => 'Quais atas estão próximas do vencimento?',
            'padroes' => [['/\batas?\b/', 2], ['/\b(venc|expir|vigencia|prazo)\w*/', 2]],
        ],
        'atas_acima_100' => [
            'titulo' => 'Atas acima de 100% de utilização',
            'descricao' => 'Atas com algum item utilizado acima da quantidade registrada.',
            'exemplo' => 'Quais atas já ultrapassaram 100% de utilização?',
            'padroes' => [['/\batas?\b/', 2], ['/\b(100|ultrapass|acima|exced|estour)\w*/', 3]],
        ],
        'compras_conjuntas' => [
            'titulo' => 'Compras que podem ser planejadas em conjunto',
            'descricao' => 'Itens comprados repetidamente por vários órgãos, candidatos a planejamento conjunto ou registro de preços.',
            'exemplo' => 'Quais compras podem ser planejadas conjuntamente?',
            'padroes' => [['/\b(conjunt|agrupad|centraliz|recorrent)\w*/', 4]],
        ],
        'participacao_mpe' => [
            'titulo' => 'Participação de MPE por órgão',
            'descricao' => 'Participação de microempreendedores, microempresas e empresas de pequeno porte no valor contratado, por órgão.',
            'exemplo' => 'Quais órgãos possuem maior participação de MPE?',
            'padroes' => [['/\b(mpe|mei|epp|microempres\w*|micro e pequen\w*|pequeno porte)\b/', 4]],
        ],
        'agricultura_familiar' => [
            'titulo' => 'Compras da agricultura familiar',
            'descricao' => 'Valor contratado de produtores da agricultura familiar e cooperativas.',
            'exemplo' => 'Quanto foi comprado da agricultura familiar?',
            'padroes' => [['/\b(agricultura familiar|agricultores?|cooperativ\w*|pnae)\b/', 5]],
        ],
        'municipios_volume' => [
            'titulo' => 'Municípios com maior volume de compras',
            'descricao' => 'Valor contratado por município da unidade compradora.',
            'exemplo' => 'Quais municípios concentram maior volume de compras?',
            'padroes' => [['/\bmunicipi|\bcidades?\b/', 4]],
        ],
        'contratos_vencendo' => [
            'titulo' => 'Contratos próximos do vencimento',
            'descricao' => 'Contratos vigentes que vencem nos próximos dias.',
            'exemplo' => 'Quais contratos vencem nos próximos 60 dias?',
            'padroes' => [['/\bcontratos?\b/', 3], ['/\b(venc|expir|vigencia|prazo|termin)\w*/', 2]],
        ],
    ];

    /** @return list<array{codigo: string, titulo: string, descricao: string, exemplo: string}> */
    public static function lista(): array
    {
        return collect(self::CONSULTAS)->map(fn ($c, $k) => ['codigo' => $k, 'titulo' => $c['titulo'], 'descricao' => $c['descricao'], 'exemplo' => $c['exemplo']])
            ->values()->all();
    }

    public static function existe(?string $codigo): bool
    {
        return $codigo !== null && isset(self::CONSULTAS[$codigo]);
    }
}
