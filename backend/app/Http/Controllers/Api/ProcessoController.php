<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListagemPaginada;
use App\Http\Controllers\Controller;
use App\Services\Compras\ItensService;
use App\Services\Compras\ProcessosService;
use App\Services\Filtros\FiltrosAplicados;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessoController extends Controller
{
    use ListagemPaginada;

    public function __construct(private readonly ProcessosService $processos) {}

    public function index(Request $request): JsonResponse
    {
        $f = FiltrosAplicados::doRequest($request);
        $local = $this->filtrosLocais($request);
        $pag = $this->processos->listar($f, $local, (string) $request->query('ordem', 'data_abertura'), $this->direcao($request), $this->pagina($request), $this->porPagina($request));

        return $this->respostaPaginada($pag, $request->boolean('com_resumo') ? ['resumo' => $this->processos->resumo($f, $local)] : []);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $d = $this->processos->detalhe($id, $request->user()->orgaosPermitidos(), $request->user()->temPermissao('dados_pessoais.ver'));
        abort_if($d === null, 404, 'Processo não encontrado.');

        return response()->json($d);
    }

    public function itens(Request $request, ItensService $itens): JsonResponse
    {
        $pag = $itens->listar(FiltrosAplicados::doRequest($request), $this->filtrosLocais($request), (string) $request->query('ordem', 'data_abertura'),
            $this->direcao($request), $this->pagina($request), $this->porPagina($request), $request->user()->temPermissao('dados_pessoais.ver'));

        return $this->respostaPaginada($pag);
    }
}
