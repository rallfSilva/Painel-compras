<?php

namespace App\Services\Integracoes;

use Carbon\CarbonImmutable;

/**
 * Conector de uma fonte externa (seção 26). O conector só coleta e traduz os registros da fonte para os
 * campos dos importadores; validação, controle de duplicidade e gravação são os mesmos da importação de arquivos.
 */
interface Conector
{
    public function codigo(): string;

    /** Origem gravada nos registros (Rastreabilidade::ORIGEM_*). */
    public function origem(): string;

    public function descricao(): string;

    public function documentacao(): string;

    /** @return array<string, array{rotulo: string, tipo: string, padrao: mixed, ajuda?: string}> */
    public function parametros(): array;

    /** @return array{ok: bool, mensagem: string} */
    public function testar(array $p): array;

    /**
     * Lotes para os importadores, na ordem de gravação.
     *
     * @return list<array{tipo: string, descricao: string, linhas: list<array<string, mixed>>, ignorados?: int}>
     */
    public function coletar(array $p, CarbonImmutable $de, CarbonImmutable $ate): array;
}
