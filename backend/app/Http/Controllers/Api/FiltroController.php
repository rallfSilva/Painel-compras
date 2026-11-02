<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Filtros\OpcoesFiltroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiltroController extends Controller
{
    public function __construct(private readonly OpcoesFiltroService $opcoes) {}

    public function opcoes(Request $request): JsonResponse
    {
        return response()->json(['opcoes' => $this->opcoes->todas($request->user()->orgaosPermitidos())]);
    }

    public function buscar(Request $request, string $tipo): JsonResponse
    {
        abort_unless(in_array($tipo, ['item', 'fornecedor', 'publicacao'], true), 404);
        $termo = (string) $request->query('q', '');

        return response()->json(['resultados' => $this->opcoes->buscar($tipo, mb_substr($termo, 0, 100), $request->user()->temPermissao('dados_pessoais.ver'))]);
    }
}
