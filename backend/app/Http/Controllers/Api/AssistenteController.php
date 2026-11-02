<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Ia\AlertasService;
use App\Services\Ia\AssistenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Etapa 6 — assistente em linguagem natural, alertas e recomendações (seção 32). */
class AssistenteController extends Controller
{
    public function configuracao(AssistenteService $s): JsonResponse
    {
        return response()->json($s->configuracao());
    }

    public function perguntar(Request $request, AssistenteService $s): JsonResponse
    {
        $dados = $request->validate(['pergunta' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json($s->perguntar($dados['pergunta'], FiltrosAplicados::doRequest($request), $request->user()));
    }

    public function alertas(Request $request, AlertasService $s): JsonResponse
    {
        return response()->json($s->gerar(FiltrosAplicados::doRequest($request)));
    }
}
