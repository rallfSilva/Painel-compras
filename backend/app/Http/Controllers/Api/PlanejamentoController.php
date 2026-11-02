<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListagemPaginada;
use App\Http\Controllers\Controller;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Planejamento\ContratosService;
use App\Services\Planejamento\PcaService;
use App\Services\Relatorios\RelatoriosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Etapa 4: PCA (seção 20), Contratos (seção 22) e Relatórios gerenciais (seção 25). */
class PlanejamentoController extends Controller
{
    use ListagemPaginada;

    public function pca(Request $request, PcaService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request)));
    }

    public function pcaDemandas(Request $request, PcaService $s): JsonResponse
    {
        $direcao = $request->query('direcao') === 'desc' ? 'desc' : 'asc';

        return $this->respostaPaginada($s->listar(FiltrosAplicados::doRequest($request), $this->filtrosLocais($request),
            (string) $request->query('ordem', 'data_pretendida'), $direcao, $this->porPagina($request), $this->pagina($request)));
    }

    public function contratos(Request $request, ContratosService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request)));
    }

    public function contratosLista(Request $request, ContratosService $s): JsonResponse
    {
        $venc = ctype_digit((string) $request->query('vencendo_em')) ? min(3650, (int) $request->query('vencendo_em')) : null;
        $direcao = $request->query('direcao') === 'desc' ? 'desc' : 'asc';

        return $this->respostaPaginada($s->listar(FiltrosAplicados::doRequest($request), $this->filtrosLocais($request), $venc ?: null,
            $request->boolean('sinalizados'), (string) $request->query('ordem', 'vigencia_fim'), $direcao, $this->porPagina($request),
            $this->pagina($request), $request->user()->temPermissao('dados_pessoais.ver')));
    }

    public function contrato(Request $request, int $id, ContratosService $s): JsonResponse
    {
        $d = $s->detalhe($id, FiltrosAplicados::doRequest($request), $request->user()->temPermissao('dados_pessoais.ver'));
        abort_if($d === null, 404, 'Contrato não encontrado.');

        return response()->json($d);
    }

    public function relatorios(RelatoriosService $s): JsonResponse
    {
        return response()->json(['relatorios' => $s->lista()]);
    }

    public function relatorio(Request $request, string $codigo, RelatoriosService $s): JsonResponse
    {
        $f = FiltrosAplicados::doRequest($request);
        $r = $s->gerar($codigo, $f, $request->user()->temPermissao('dados_pessoais.ver'));
        DB::table('logs_auditoria')->insert(['user_id' => $request->user()->id, 'acao' => 'relatorio.gerar', 'recurso' => 'relatorios',
            'ip' => $request->ip(), 'detalhes' => json_encode(['codigo' => $codigo, 'filtros' => $f->selecoes, 'periodo' => $f->periodo, 'linhas' => $r['total_linhas']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now()]);

        return response()->json($r + ['periodo' => $f->descricaoPeriodo()]);
    }
}
