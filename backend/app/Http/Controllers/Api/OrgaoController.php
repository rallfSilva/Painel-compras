<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Compras\OrgaosService;
use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Indicadores\OpcoesCalculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrgaoController extends Controller
{
    public function __construct(private readonly OrgaosService $orgaos) {}

    public function arvore(Request $request): JsonResponse
    {
        return response()->json(['dados' => $this->orgaos->arvore(FiltrosAplicados::doRequest($request))]);
    }

    public function unidade(Request $request, int $id, Configuracoes $config): JsonResponse
    {
        $d = $this->orgaos->unidade($id, FiltrosAplicados::doRequest($request), OpcoesCalculo::doRequest($request, $config));
        abort_if($d === null, 404, 'Unidade compradora não encontrada.');

        return response()->json($d);
    }
}
