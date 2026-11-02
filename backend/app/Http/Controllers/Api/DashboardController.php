<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Indicadores\DashboardService;
use App\Services\Indicadores\OpcoesCalculo;
use App\Services\Indicadores\RegistrosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly RegistrosService $registros,
        private readonly Configuracoes $config,
    ) {}

    public function indicadores(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->indicadores(FiltrosAplicados::doRequest($request), OpcoesCalculo::doRequest($request, $this->config)));
    }

    public function graficos(Request $request): JsonResponse
    {
        $f = FiltrosAplicados::doRequest($request);
        $o = OpcoesCalculo::doRequest($request, $this->config);

        return response()->json([
            'evolucao_anual' => $this->dashboard->evolucaoAnual($f, $o),
            'por_sistematica' => $this->dashboard->porSistematica($f),
            'top_unidades' => $this->dashboard->topUnidades($f),
            'por_grupo_porte' => $this->dashboard->porGrupoPorte($f),
        ]);
    }

    public function registros(Request $request, string $codigo): JsonResponse
    {
        $pagina = max(1, (int) $request->query('pagina', 1));
        $porPagina = min(100, max(5, (int) $request->query('por_pagina', 20)));
        $resultado = $this->registros->listar(
            $codigo,
            FiltrosAplicados::doRequest($request),
            OpcoesCalculo::doRequest($request, $this->config),
            $pagina,
            $porPagina,
            $request->user()->temPermissao('dados_pessoais.ver'),
        );
        abort_if($resultado === null, 404, 'Indicador desconhecido.');

        return response()->json([
            'dados' => $resultado->items(),
            'pagina' => $resultado->currentPage(),
            'por_pagina' => $resultado->perPage(),
            'total' => $resultado->total(),
            'ultima_pagina' => $resultado->lastPage(),
        ]);
    }
}
