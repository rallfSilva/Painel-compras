<?php

namespace App\Services\Inteligencia;

use App\Services\Indicadores\IndicadorService;

/** Trechos de SQL e conversões repetidos nos painéis de inteligência (seções 9 a 15). */
final class Sql
{
    /** Item com resultado (adjudicado, homologado ou contratado). */
    public static function resultado(): string
    {
        return "pi.situacao IN ('".implode("','", IndicadorService::SITUACOES_RESULTADO)."')";
    }

    /** Porte do fornecedor como agricultura familiar (produtor familiar ou cooperativa). */
    public const AGRICULTURA_FAMILIAR = "pe.codigo IN ('PAF','COOP')";

    public static function valor(mixed $v): ?float
    {
        return $v !== null ? round((float) $v, 2) : null;
    }

    public static function pct(mixed $parte, mixed $todo): ?float
    {
        return (float) $todo > 0 ? round((float) $parte / (float) $todo * 100, 2) : null;
    }
}
