<?php

namespace App\Services\Importacao;

/** Estado compartilhado durante uma importação: origem, rastreio, referências e registros afetados. */
final class Contexto
{
    public readonly Referencias $ref;

    /** @var array<string, array<int, true>> registros tocados por tipo (para recálculos ao final) */
    public array $tocados = [];

    public function __construct(public readonly ?int $importacaoId, public readonly string $origem, public readonly ?int $userId = null)
    {
        $this->ref = new Referencias;
    }

    public function tocar(string $tipo, int $id): void
    {
        $this->tocados[$tipo][$id] = true;
    }

    /** @return list<int> */
    public function ids(string $tipo): array
    {
        return array_keys($this->tocados[$tipo] ?? []);
    }
}
