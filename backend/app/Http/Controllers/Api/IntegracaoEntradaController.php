<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListagemPaginada;
use App\Http\Controllers\Controller;
use App\Services\Importacao\RegistroImportadores;
use App\Services\Integracoes\Entrada\EntradaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API de entrada para outros sistemas (/api/integracao/v1). Autenticação só por token de integração. */
class IntegracaoEntradaController extends Controller
{
    use ListagemPaginada;

    public function tipos(EntradaService $s): JsonResponse
    {
        return response()->json(['tipos' => $s->tipos(), 'limite_registros_por_lote' => EntradaService::LIMITE_REGISTROS]);
    }

    public function receber(Request $request, EntradaService $s): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', 'string', 'in:'.implode(',', array_keys(RegistroImportadores::todos()))],
            'referencia' => ['nullable', 'string', 'max:150'],
            'registros' => ['required', 'array', 'min:1', 'max:'.EntradaService::LIMITE_REGISTROS],
        ]);
        $chave = $request->header('Idempotency-Key');
        if ($chave !== null && ! preg_match('/^[A-Za-z0-9._:\-]{8,100}$/', $chave)) {
            return response()->json(['message' => 'Idempotency-Key deve ter de 8 a 100 caracteres (letras, números, ponto, hífen, dois-pontos ou sublinhado).'], 422);
        }
        // O nome do token é o código do sistema (ex.: NEXPLANGOV) e vira a origem dos registros.
        $origem = (string) $request->user()->currentAccessToken()->name;
        $r = $s->receber($request->user(), $origem, $d['tipo'], array_values($d['registros']), $chave, $d['referencia'] ?? null);

        return response()->json($r['lote'], $r['novo'] ? 202 : 200);
    }

    public function lote(Request $request, int $id, EntradaService $s): JsonResponse
    {
        return response()->json($s->lote($request->user(), $id));
    }

    public function erros(Request $request, int $id, EntradaService $s): JsonResponse
    {
        return $this->respostaPaginada($s->erros($request->user(), $id, $this->porPagina($request), $this->pagina($request)));
    }
}
