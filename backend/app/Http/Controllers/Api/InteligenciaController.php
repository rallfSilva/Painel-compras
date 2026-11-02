<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListagemPaginada;
use App\Http\Controllers\Controller;
use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Indicadores\OpcoesCalculo;
use App\Services\Inteligencia\AlimentosService;
use App\Services\Inteligencia\AquisicoesRpService;
use App\Services\Inteligencia\GeograficoService;
use App\Services\Inteligencia\LicitacoesRpService;
use App\Services\Inteligencia\MpeService;
use App\Services\Inteligencia\NaturezaService;
use App\Services\Inteligencia\SistematicaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Painéis de inteligência da Etapa 3A (seções 9 a 15). */
class InteligenciaController extends Controller
{
    use ListagemPaginada;

    public function __construct(private readonly Configuracoes $config) {}

    public function mpe(Request $request, MpeService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request)));
    }

    public function alimentos(Request $request, AlimentosService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request)));
    }

    public function licitacoesRp(Request $request, LicitacoesRpService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request)));
    }

    public function licitacoesRpProcessos(Request $request, LicitacoesRpService $s): JsonResponse
    {
        return $this->respostaPaginada($s->processos(
            FiltrosAplicados::doRequest($request), $this->filtrosLocais($request),
            (string) $request->query('ordem', 'data_publicacao'), $this->direcao($request),
            $this->porPagina($request), $this->pagina($request),
        ));
    }

    public function aquisicoesRp(Request $request, AquisicoesRpService $s): JsonResponse
    {
        $id = fn (string $k) => ctype_digit((string) $request->query($k)) ? (int) $request->query($k) : null;

        return response()->json($s->painel(FiltrosAplicados::doRequest($request), [
            'ata_id' => $id('ata_id'), 'item_id' => $id('item_id'), 'unidade_id' => $id('unidade_id'),
        ]));
    }

    public function geografico(Request $request, GeograficoService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request)));
    }

    public function municipio(Request $request, int $id, GeograficoService $s): JsonResponse
    {
        $d = $s->municipio($id, FiltrosAplicados::doRequest($request), OpcoesCalculo::doRequest($request, $this->config));
        abort_if($d === null, 404, 'Município não encontrado.');

        return response()->json($d);
    }

    public function natureza(Request $request, NaturezaService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request)));
    }

    public function item(Request $request, int $id, NaturezaService $s): JsonResponse
    {
        $d = $s->item($id, FiltrosAplicados::doRequest($request));
        abort_if($d === null, 404, 'Item não encontrado.');

        return response()->json($d);
    }

    public function sistematica(Request $request, SistematicaService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request), OpcoesCalculo::doRequest($request, $this->config)));
    }
}
