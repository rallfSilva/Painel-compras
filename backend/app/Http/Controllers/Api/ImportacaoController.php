<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListagemPaginada;
use App\Http\Controllers\Controller;
use App\Services\Importacao\ImportacaoService;
use App\Services\Integracoes\IntegracoesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Etapa 5: central de importação de dados e integrações externas (seção 26). */
class ImportacaoController extends Controller
{
    use ListagemPaginada;

    public function tipos(ImportacaoService $s): JsonResponse
    {
        return response()->json(['tipos' => $s->tipos()]);
    }

    public function listar(Request $request, ImportacaoService $s): JsonResponse
    {
        $local = $this->filtrosLocais($request);

        return $this->respostaPaginada($s->listar(['tipo' => $request->query('tipo'), 'status' => $local['situacao'], 'origem' => $request->query('origem'),
            'busca' => $local['busca'] ?: null], $this->porPagina($request), $this->pagina($request)));
    }

    public function receber(Request $request, ImportacaoService $s): JsonResponse
    {
        $d = $request->validate(['arquivo' => ['required', 'file', 'max:20480'], 'tipo' => ['required', 'string', 'max:40']]);

        return response()->json($s->receber($d['arquivo'], $d['tipo'], $request->user()), 201);
    }

    public function detalhe(int $id, ImportacaoService $s): JsonResponse
    {
        return response()->json($s->detalhe($id));
    }

    public function validar(Request $request, int $id, ImportacaoService $s): JsonResponse
    {
        $d = $request->validate(['mapeamento' => ['present', 'array']]);

        return response()->json($s->validar($id, $d['mapeamento']));
    }

    public function confirmar(Request $request, int $id, ImportacaoService $s): JsonResponse
    {
        return response()->json($s->confirmar($id, $request->user()));
    }

    public function reprocessar(Request $request, int $id, ImportacaoService $s): JsonResponse
    {
        return response()->json($s->reprocessar($id, $request->user()), 201);
    }

    public function excluir(Request $request, int $id, ImportacaoService $s): JsonResponse
    {
        $s->excluir($id, $request->user());

        return response()->json(['ok' => true]);
    }

    public function erros(Request $request, int $id, ImportacaoService $s): JsonResponse
    {
        $s->detalhe($id);

        return $this->respostaPaginada($s->erros($id, $this->porPagina($request, 50), $this->pagina($request), $this->filtrosLocais($request)['busca']));
    }

    public function integracoes(IntegracoesService $s): JsonResponse
    {
        return response()->json(['integracoes' => $s->listar()]);
    }

    public function salvarIntegracao(Request $request, string $codigo, IntegracoesService $s): JsonResponse
    {
        $request->validate(['ativa' => ['boolean'], 'parametros' => ['array']]);
        $s->salvar(strtoupper($codigo), $request->only(['ativa', 'parametros']), $request->user()->id);

        return response()->json(['integracoes' => $s->listar()]);
    }

    public function testarIntegracao(string $codigo, IntegracoesService $s): JsonResponse
    {
        return response()->json($s->testar(strtoupper($codigo)));
    }

    public function sincronizar(Request $request, string $codigo, IntegracoesService $s): JsonResponse
    {
        $d = $request->validate(['de' => ['nullable', 'date'], 'ate' => ['nullable', 'date', 'after_or_equal:de']]);
        $log = $s->agendar(strtoupper($codigo), $request->user()->id, $d['de'] ?? null, $d['ate'] ?? null);

        return response()->json(['log_id' => $log, 'integracoes' => $s->listar()], 202);
    }
}
