<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Leitura dos parâmetros da tabela configuracoes (gerenciados na Administração). */
class Configuracoes
{
    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    public function get(string $chave, ?string $padrao = null): ?string
    {
        $this->cache ??= DB::table('configuracoes')->pluck('valor', 'chave')->all();

        return $this->cache[$chave] ?? $padrao;
    }

    public function baseEconomia(): string
    {
        $base = $this->get('economia.base_comparacao', 'contratado');

        return in_array($base, ['adjudicado', 'homologado', 'contratado'], true) ? $base : 'contratado';
    }

    public function incluirSemDisputa(): bool
    {
        return filter_var($this->get('economia.incluir_sem_disputa', '0'), FILTER_VALIDATE_BOOL);
    }

    public function int(string $chave, int $padrao): int
    {
        $v = $this->get($chave);

        return is_numeric($v) ? (int) $v : $padrao;
    }
}
