<?php

namespace App\Support;

/** Proteção de dados pessoais (LGPD, seção 23/30): CPF de fornecedor pessoa física mascarado sem permissão. */
final class DadosPessoais
{
    public static function mascararCpf(string $cpf): string
    {
        return '***.'.substr($cpf, 3, 3).'.***-**';
    }

    /**
     * Mascara fornecedor_documento quando fornecedor_tipo = F e remove fornecedor_tipo da linha.
     */
    public static function mascararLinha(object $r, bool $ver): object
    {
        if (! $ver && isset($r->fornecedor_documento, $r->fornecedor_tipo) && $r->fornecedor_tipo === 'F') {
            $r->fornecedor_documento = self::mascararCpf($r->fornecedor_documento);
        }
        unset($r->fornecedor_tipo);

        return $r;
    }
}
