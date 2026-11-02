<?php

namespace App\Services\Importacao;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Conversão dos valores lidos dos arquivos. Cada função devolve o valor convertido ou lança
 * \InvalidArgumentException com a mensagem que vai para o relatório de inconsistências.
 */
final class Valor
{
    public const TIPOS = ['texto', 'numero', 'decimal', 'data', 'documento', 'booleano', 'codigo'];

    /** Chave comparável de um nome de coluna ou de um texto de referência: minúsculas, sem acentos, só letras e números. */
    public static function chave(?string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii((string) $s))), '_');
    }

    public static function vazio(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }

    public static function converter(string $tipo, mixed $v): mixed
    {
        if (self::vazio($v)) {
            return null;
        }

        return match ($tipo) {
            'numero', 'decimal' => self::numero($v),
            'data' => self::data($v),
            'documento' => self::documento($v),
            'booleano' => self::booleano($v),
            'codigo' => mb_strtoupper(trim((string) $v)),
            default => self::texto($v),
        };
    }

    public static function texto(mixed $v): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $v));
        if (mb_strlen($t) > 2000) {
            throw new \InvalidArgumentException('texto com mais de 2.000 caracteres');
        }

        return $t;
    }

    /** Aceita 1.234,56 (padrão brasileiro), 1234.56, 1,234.56 e números da planilha. */
    public static function numero(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^\d,.\-]/', '', str_replace(['R$', ' '], '', (string) $v));
        if ($s === '' || $s === '-') {
            throw new \InvalidArgumentException('número inválido');
        }
        $virgula = strrpos($s, ',');
        $ponto = strrpos($s, '.');
        if ($virgula !== false && ($ponto === false || $virgula > $ponto)) {
            $s = str_replace(['.', ','], ['', '.'], $s);        // 1.234,56
        } elseif ($virgula !== false) {
            $s = str_replace(',', '', $s);                       // 1,234.56
        } elseif ($ponto !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);                       // 1.234.567
        }
        if (! is_numeric($s)) {
            throw new \InvalidArgumentException('número inválido');
        }

        return (float) $s;
    }

    /** Aceita dd/mm/aaaa, aaaa-mm-dd (com ou sem hora) e o número serial de data do Excel. */
    public static function data(mixed $v): string
    {
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        $s = trim((string) $v);
        foreach (['d/m/Y', 'd/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'd-m-Y', 'd.m.Y', 'd/m/y'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!'.$f, $s);
            $e = \DateTimeImmutable::getLastErrors();
            if ($d !== false && ($e === false || ($e['warning_count'] === 0 && $e['error_count'] === 0))) {
                $ano = (int) $d->format('Y');
                if ($ano < 1990 || $ano > 2100) {
                    throw new \InvalidArgumentException('data fora do intervalo 1990–2100');
                }

                return $d->format('Y-m-d');
            }
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T/', $s, $m)) {
            return self::data($m[1]);
        }
        throw new \InvalidArgumentException('data inválida (use dd/mm/aaaa ou aaaa-mm-dd)');
    }

    /** CPF ou CNPJ só com dígitos, com verificação dos dígitos verificadores. */
    public static function documento(mixed $v): string
    {
        $d = preg_replace('/\D/', '', (string) $v);
        if (strlen($d) === 10 || strlen($d) === 13) {
            $d = '0'.$d;                                         // zero à esquerda perdido na planilha
        }
        if (strlen($d) !== 11 && strlen($d) !== 14) {
            throw new \InvalidArgumentException('CPF/CNPJ deve ter 11 ou 14 dígitos');
        }
        if (! self::documentoValido($d)) {
            throw new \InvalidArgumentException('CPF/CNPJ com dígito verificador inválido');
        }

        return $d;
    }

    public static function documentoValido(string $d): bool
    {
        if (preg_match('/^(\d)\1+$/', $d)) {
            return false;
        }
        if (strlen($d) === 11) {
            for ($t = 9; $t < 11; $t++) {
                $s = 0;
                for ($i = 0; $i < $t; $i++) {
                    $s += (int) $d[$i] * (($t + 1) - $i);
                }
                if ((int) $d[$t] !== ((10 * $s) % 11) % 10) {
                    return false;
                }
            }

            return true;
        }
        foreach ([12, 13] as $t) {
            $pesos = $t === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $s = 0;
            foreach ($pesos as $i => $p) {
                $s += (int) $d[$i] * $p;
            }
            $r = $s % 11 < 2 ? 0 : 11 - $s % 11;
            if ((int) $d[$t] !== $r) {
                return false;
            }
        }

        return true;
    }

    public static function booleano(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = self::chave((string) $v);

        return match (true) {
            in_array($s, ['1', 's', 'sim', 'true', 'verdadeiro', 'x', 'y', 'yes'], true) => true,
            in_array($s, ['0', 'n', 'nao', 'false', 'falso', 'no'], true) => false,
            default => throw new \InvalidArgumentException('use Sim ou Não'),
        };
    }
}
