<?php

namespace App\Services\Ia;

/**
 * Resultado da interpretação de uma pergunta: qual consulta autorizada executar e com quais parâmetros.
 * É tudo o que o interpretador (regras locais ou modelo de linguagem) pode produzir; os dados vêm depois,
 * das consultas do sistema.
 *
 * @phpstan-type Parametros array{termo?: string, anos?: list<int>, periodo?: string, orgaos?: list<string>, limite?: int, dias?: int}
 */
final class Interpretacao
{
    /** @param  array<string, mixed>  $parametros */
    public function __construct(
        public readonly ?string $consulta,
        public readonly array $parametros,
        public readonly string $interpretador,
        public readonly ?string $observacao = null,
    ) {}
}
