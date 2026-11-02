<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GlossarioIndicador;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MetaController extends Controller
{
    /** Período coberto pelos dados e data da última atualização (cabeçalho, seção 4.2). */
    public function meta(): JsonResponse
    {
        $periodo = DB::table('processos')->selectRaw('MIN(data_abertura) AS inicio, MAX(data_abertura) AS fim')->first();
        $ultimaCarga = collect([
            DB::table('importacoes')->where('status', 'CONCLUIDO')->max('concluida_em'),
            DB::table('integracoes')->max('ultima_sincronizacao_em'),
            DB::table('processos')->max('updated_at'),
        ])->filter()->max();

        return response()->json([
            'sistema' => config('app.name'),
            'periodo_dados' => ['inicio' => $periodo->inicio, 'fim' => $periodo->fim],
            'ultima_atualizacao' => $ultimaCarga,
            'possui_dados_ficticios' => DB::table('processos')->where('ficticio', true)->exists(),
            'possui_dados_oficiais' => DB::table('processos')->where('ficticio', false)->exists(),
        ]);
    }

    public function glossario(): JsonResponse
    {
        return response()->json(['glossario' => GlossarioIndicador::orderBy('nome')->get()]);
    }
}
