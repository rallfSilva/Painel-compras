<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** Parâmetros comuns das listagens: página, tamanho, ordenação, busca e filtros locais do módulo. */
trait ListagemPaginada
{
    /** Tamanho máximo de página para exportação (perfil com relatorios.exportar). */
    private const MAXIMO_EXPORTACAO = 5000;

    protected function pagina(Request $request): int
    {
        return max(1, (int) $request->query('pagina', 1));
    }

    protected function porPagina(Request $request, int $padrao = 25): int
    {
        $max = $request->boolean('exportar') && $request->user()->temPermissao('relatorios.exportar') ? self::MAXIMO_EXPORTACAO : 100;

        return min($max, max(5, (int) $request->query('por_pagina', $padrao)));
    }

    protected function direcao(Request $request): string
    {
        return $request->query('direcao') === 'asc' ? 'asc' : 'desc';
    }

    /** @return array{busca: string, situacao: list<string>} */
    protected function filtrosLocais(Request $request): array
    {
        $situacao = $request->query('situacao', []);

        return [
            'busca' => mb_substr(trim((string) $request->query('busca', '')), 0, 120),
            'situacao' => array_values(array_filter(is_array($situacao) ? $situacao : [$situacao], 'is_string')),
        ];
    }

    protected function respostaPaginada(LengthAwarePaginator $p, array $extra = []): JsonResponse
    {
        return response()->json([
            'dados' => $p->items(),
            'pagina' => $p->currentPage(),
            'por_pagina' => $p->perPage(),
            'total' => $p->total(),
            'ultima_pagina' => $p->lastPage(),
        ] + $extra);
    }
}
