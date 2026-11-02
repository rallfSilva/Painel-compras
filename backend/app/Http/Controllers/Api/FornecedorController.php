<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListagemPaginada;
use App\Http\Controllers\Controller;
use App\Services\Compras\FornecedoresService;
use App\Services\Filtros\FiltrosAplicados;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FornecedorController extends Controller
{
    use ListagemPaginada;

    public function __construct(private readonly FornecedoresService $fornecedores) {}

    public function index(Request $request): JsonResponse
    {
        $r = $this->fornecedores->ranking(FiltrosAplicados::doRequest($request), $this->filtrosLocais($request), (string) $request->query('ordem', 'contratado'),
            $this->direcao($request), $this->pagina($request), $this->porPagina($request), $request->user()->temPermissao('dados_pessoais.ver'));

        return $this->respostaPaginada($r['paginacao'], ['total_contratado' => $r['total_contratado']]);
    }

    public function concentracao(Request $request): JsonResponse
    {
        return response()->json($this->fornecedores->concentracao(FiltrosAplicados::doRequest($request)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $d = $this->fornecedores->detalhe($id, FiltrosAplicados::doRequest($request), $request->user()->temPermissao('dados_pessoais.ver'));
        abort_if($d === null, 404, 'Fornecedor não encontrado.');

        return response()->json($d);
    }
}
