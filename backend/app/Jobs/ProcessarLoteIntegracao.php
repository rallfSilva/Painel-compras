<?php

namespace App\Jobs;

use App\Services\Integracoes\Entrada\EntradaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Valida e grava um lote recebido pela API de entrada, em segundo plano e na ordem de chegada. */
class ProcessarLoteIntegracao implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $importacaoId) {}

    public function handle(EntradaService $servico): void
    {
        $servico->processar($this->importacaoId);
    }
}
