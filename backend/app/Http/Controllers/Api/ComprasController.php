<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Compras\ComprasTotaisService;
use App\Services\Compras\PanoramaService;
use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Indicadores\OpcoesCalculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComprasController extends Controller
{
    public function __construct(private readonly Configuracoes $config) {}

    public function graficos(Request $request, ComprasTotaisService $compras): JsonResponse
    {
        return response()->json($compras->graficos(FiltrosAplicados::doRequest($request), OpcoesCalculo::doRequest($request, $this->config)));
    }

    public function panorama(Request $request, PanoramaService $panorama): JsonResponse
    {
        $metrica = $request->query('metrica_disputa') === 'valor' ? 'valor' : 'quantidade';
        $regiao = ctype_digit((string) $request->query('regiao_id')) ? (int) $request->query('regiao_id') : null;

        return response()->json($panorama->tabela(FiltrosAplicados::doRequest($request), OpcoesCalculo::doRequest($request, $this->config), $metrica, $regiao));
    }
}
