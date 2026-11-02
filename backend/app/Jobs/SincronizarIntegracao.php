<?php

namespace App\Jobs;

use App\Services\Integracoes\IntegracoesService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sincroniza uma integração externa em segundo plano. */
class SincronizarIntegracao implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly string $codigo, public readonly int $logId, public readonly ?string $de = null, public readonly ?string $ate = null) {}

    public function handle(IntegracoesService $servico): void
    {
        $servico->sincronizar($this->codigo, $this->logId, $this->de, $this->ate);
    }
}
