<?php

namespace App\Support;

/**
 * Normalização das descrições do catálogo (seção 17): facilita a busca de duplicidades e a análise de
 * preços. Só padroniza a escrita; nunca une itens. A equivalência entre itens exige validação técnica.
 */
final class Normalizador
{
    /** Abreviações comuns padronizadas (aplicadas palavra a palavra). */
    private const ABREVIACOES = [
        'UNID' => 'UN', 'UND' => 'UN', 'UNIDADE' => 'UN', 'PCT' => 'PACOTE', 'PC' => 'PECA', 'CX' => 'CAIXA',
        'KILO' => 'KG', 'KILOS' => 'KG', 'QUILO' => 'KG', 'GRS' => 'G', 'GR' => 'G', 'LT' => 'L', 'LITRO' => 'L', 'LITROS' => 'L',
        'MTS' => 'M', 'METRO' => 'M', 'METROS' => 'M', 'TAM' => 'TAMANHO', 'C' => 'COM', 'S' => 'SEM',
    ];

    public static function descricao(?string $texto): string
    {
        $t = mb_strtoupper(trim((string) $texto));
        $t = strtr($t, ['Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Í' => 'I', 'Ì' => 'I',
            'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N']);
        $t = preg_replace('/(\d),(\d)/', '$1.$2', $t);            // 0,9% -> 0.9%
        $t = preg_replace('/(\d)\s+(KG|G|MG|ML|L|M|CM|MM|M2|M3)\b/', '$1$2', $t); // 5 KG -> 5KG
        $t = preg_replace('/[^A-Z0-9.%\/ ]+/', ' ', $t);
        $t = preg_replace('/\s*\/\s*/', ' ', $t);                 // C/ -> C
        $palavras = array_filter(explode(' ', $t), fn ($p) => $p !== '' && $p !== '.');
        $palavras = array_map(fn ($p) => self::ABREVIACOES[$p] ?? $p, $palavras);

        return implode(' ', $palavras);
    }

    /** Semelhança de Jaccard entre os termos de duas descrições normalizadas (0 a 1). */
    public static function semelhanca(string $a, string $b): float
    {
        // Letras e números isolados contam (TAMANHO M x TAMANHO G); só as palavras de ligação saem.
        $ligacao = ['DE', 'DA', 'DO', 'DAS', 'DOS', 'E', 'EM', 'PARA', 'A', 'O'];
        $ta = array_unique(array_filter(explode(' ', $a), fn ($p) => $p !== '' && ! in_array($p, $ligacao, true)));
        $tb = array_unique(array_filter(explode(' ', $b), fn ($p) => $p !== '' && ! in_array($p, $ligacao, true)));
        $uniao = count(array_unique(array_merge($ta, $tb)));

        return $uniao > 0 ? count(array_intersect($ta, $tb)) / $uniao : 0.0;
    }
}
