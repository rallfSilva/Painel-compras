<?php

namespace App\Services\Ia;

use Illuminate\Support\Str;

/** Normalização de texto em português para comparar perguntas e nomes do cadastro. */
final class Texto
{
    private const VAZIAS = [
        'a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'e', 'em', 'no', 'na', 'nos', 'nas', 'com', 'para', 'por', 'pela', 'pelo',
        'um', 'uma', 'ao', 'aos', 'que', 'qual', 'quais', 'quanto', 'quanta', 'foi', 'foram', 'sao', 'governo', 'estado', 'roraima',
        'material', 'materiais', 'produto', 'produtos', 'item', 'itens', 'tipo', 'tipos', 'geral', 'total', 'area', 'setor', 'ano',
    ];

    public static function normalizar(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($s))));
    }

    /** @return list<string> radicais das palavras significativas */
    public static function radicais(string $s): array
    {
        $palavras = preg_split('/[^a-z0-9]+/', self::normalizar($s), -1, PREG_SPLIT_NO_EMPTY);
        $r = [];
        foreach ($palavras as $p) {
            if (in_array($p, self::VAZIAS, true) || ctype_digit($p)) {
                continue;
            }
            $r[] = self::radical($p);
        }

        return array_values(array_unique($r));
    }

    /** Radical simples: tira o plural e corta palavras longas, para casar "computadores" com "computador". */
    public static function radical(string $p): string
    {
        if (strlen($p) > 3) {
            $p = preg_replace('/(oes|aes|ais|eis|res|ns|s)$/', '', $p) ?: $p;
        }

        return strlen($p) > 7 ? substr($p, 0, 7) : $p;
    }
}
