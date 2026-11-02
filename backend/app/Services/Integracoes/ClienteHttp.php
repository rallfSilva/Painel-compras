<?php

namespace App\Services\Integracoes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Cliente HTTP comum às integrações: tempo limite, novas tentativas e paginação. */
trait ClienteHttp
{
    protected function http(?string $token = null): PendingRequest
    {
        $h = Http::acceptJson()->timeout(30)->connectTimeout(10)->retry(2, 1500, throw: false)
            ->withHeaders(['User-Agent' => 'SIC-SELC/1.0 (Governo de Roraima)']);

        return $token ? $h->withToken($token) : $h;
    }

    /**
     * Busca uma URL e devolve o JSON; 204 (sem conteúdo) vira lista vazia.
     *
     * @return array<mixed>
     */
    protected function obterJson(string $url, array $query = [], ?string $token = null): array
    {
        $r = $this->http($token)->get($url, $query);
        if ($r->status() === 204) {
            return [];
        }
        if (! $r->successful()) {
            throw new \RuntimeException("A fonte respondeu HTTP {$r->status()} em ".parse_url($url, PHP_URL_HOST).'.');
        }
        $j = $r->json();
        if (! is_array($j)) {
            throw new \RuntimeException('A fonte não devolveu JSON válido.');
        }

        return $j;
    }
}
