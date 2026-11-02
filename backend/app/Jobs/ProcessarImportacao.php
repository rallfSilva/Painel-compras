<?php

namespace App\Jobs;

use App\Services\Importacao\ImportacaoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Processa uma importação confirmada, em segundo plano. */
class ProcessarImportacao implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $importacaoId) {}

    public function handle(ImportacaoService $servico): void
    {
        $servico->processar($this->importacaoId);
    }
}
