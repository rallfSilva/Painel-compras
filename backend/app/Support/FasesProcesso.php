<?php

namespace App\Support;

/**
 * Fases do processo de contratação (seção 21), adaptadas ao procedimento.
 * Licitações seguem o rito completo; contratações diretas, adesões e chamadas públicas têm rito reduzido.
 */
final class FasesProcesso
{
    public const NOMES = [
        'PLANEJAMENTO' => 'Planejamento',
        'PREPARATORIA' => 'Fase preparatória',
        'DIVULGACAO' => 'Divulgação',
        'PROPOSTAS' => 'Apresentação de propostas',
        'JULGAMENTO' => 'Julgamento',
        'HABILITACAO' => 'Habilitação',
        'RECURSOS' => 'Recursos',
        'ADJUDICACAO' => 'Adjudicação',
        'HOMOLOGACAO' => 'Homologação',
        'CONTRATACAO' => 'Contratação',
        'EXECUCAO' => 'Execução contratual',
    ];

    /** @return list<string> */
    public static function doProcedimento(?string $sistematica): array
    {
        return match ($sistematica) {
            'DISPENSA' => ['PLANEJAMENTO', 'PREPARATORIA', 'DIVULGACAO', 'PROPOSTAS', 'HABILITACAO', 'ADJUDICACAO', 'HOMOLOGACAO', 'CONTRATACAO', 'EXECUCAO'],
            'INEXIGIBILIDADE' => ['PLANEJAMENTO', 'PREPARATORIA', 'HABILITACAO', 'HOMOLOGACAO', 'CONTRATACAO', 'EXECUCAO'],
            'ADESAO_ATA' => ['PLANEJAMENTO', 'PREPARATORIA', 'HOMOLOGACAO', 'CONTRATACAO', 'EXECUCAO'],
            'CREDENCIAMENTO' => ['PLANEJAMENTO', 'PREPARATORIA', 'DIVULGACAO', 'HABILITACAO', 'HOMOLOGACAO', 'CONTRATACAO', 'EXECUCAO'],
            'CHAMADA_PNAE' => ['PLANEJAMENTO', 'PREPARATORIA', 'DIVULGACAO', 'PROPOSTAS', 'HABILITACAO', 'HOMOLOGACAO', 'CONTRATACAO', 'EXECUCAO'],
            default => array_keys(self::NOMES),
        };
    }
}
