<?php

namespace App\Services\Indicadores;

use App\Services\Configuracoes;
use Illuminate\Http\Request;

/** Opções de cálculo que o usuário pode alterar na interface, com padrão vindo das configurações. */
final class OpcoesCalculo
{
    public function __construct(
        public readonly string $baseEconomia = 'contratado',
        public readonly bool $incluirSemDisputa = false,
        public readonly int $diasVencimentoContratos = 90,
    ) {}

    public static function doRequest(Request $request, Configuracoes $config): self
    {
        $base = $request->query('economia_base');
        $incluir = $request->query('incluir_sem_disputa');

        return new self(
            baseEconomia: in_array($base, ['adjudicado', 'homologado', 'contratado'], true) ? $base : $config->baseEconomia(),
            incluirSemDisputa: $incluir !== null ? filter_var($incluir, FILTER_VALIDATE_BOOL) : $config->incluirSemDisputa(),
            diasVencimentoContratos: $config->int('contratos.dias_alerta_vencimento', 90),
        );
    }

    public function colunaReferencia(): string
    {
        return 'pi.valor_total_'.$this->baseEconomia;
    }
}
