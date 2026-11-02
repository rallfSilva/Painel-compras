<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListagemPaginada;
use App\Http\Controllers\Controller;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Inteligencia\AnaliseTotalService;
use App\Services\Inteligencia\CatalogoService;
use App\Services\Inteligencia\ClassesService;
use App\Services\Inteligencia\ItensAdjudicadosService;
use App\Services\Inteligencia\PrecosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Painéis de inteligência da Etapa 3B (seções 16 a 19 e 24). */
class AnaliseController extends Controller
{
    use ListagemPaginada;

    // ---- Itens adjudicados (16)

    public function itensAdjudicados(Request $request, ItensAdjudicadosService $s): JsonResponse
    {
        return $this->respostaPaginada($s->listar(
            FiltrosAplicados::doRequest($request), $this->filtrosLocais($request),
            (string) $request->query('ordem', 'data_abertura'), $this->direcao($request),
            $this->pagina($request), $this->porPagina($request), $request->user()->temPermissao('dados_pessoais.ver'),
            AnaliseTotalService::lerCaminho((string) $request->query('caminho', '')),
        ));
    }

    // ---- Compras por classe (18)

    public function classes(Request $request, ClassesService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request)));
    }

    public function classe(Request $request, int $id, ClassesService $s): JsonResponse
    {
        $d = $s->classe($id, FiltrosAplicados::doRequest($request));
        abort_if($d === null, 404, 'Classe não encontrada.');

        return response()->json($d);
    }

    // ---- Catálogo (17)

    public function catalogo(Request $request, CatalogoService $s): JsonResponse
    {
        $status = $request->query('status', []);
        $local = [
            'busca' => mb_substr(trim((string) $request->query('busca', '')), 0, 120),
            'status' => array_values(array_intersect(is_array($status) ? $status : [$status], CatalogoService::STATUS)),
            'grupo_id' => ctype_digit((string) $request->query('grupo_id')) ? (int) $request->query('grupo_id') : null,
            'classe_id' => ctype_digit((string) $request->query('classe_id')) ? (int) $request->query('classe_id') : null,
            'apenas_duplicados' => $request->boolean('apenas_duplicados'),
        ];
        $direcao = $request->query('direcao') === 'desc' ? 'desc' : 'asc';

        return $this->respostaPaginada($s->listar($local, (string) $request->query('ordem', 'codigo'), $direcao, $this->porPagina($request), $this->pagina($request)));
    }

    public function catalogoResumo(CatalogoService $s): JsonResponse
    {
        return response()->json($s->resumo() + ['opcoes' => $s->opcoes()]);
    }

    public function catalogoItem(int $id, CatalogoService $s): JsonResponse
    {
        $d = $s->detalhe($id);
        abort_if($d === null, 404, 'Item não encontrado.');

        return response()->json($d);
    }

    public function catalogoDuplicidades(CatalogoService $s): JsonResponse
    {
        return response()->json(['grupos' => $s->duplicidades()]);
    }

    public function catalogoEquivalencias(Request $request, CatalogoService $s): JsonResponse
    {
        $status = in_array($request->query('status'), ['PROPOSTA', 'VALIDADA', 'REJEITADA'], true) ? $request->query('status') : null;

        return response()->json(['equivalencias' => $s->equivalenciasDe(null, $status)]);
    }

    public function catalogoSalvar(Request $request, CatalogoService $s, ?int $id = null): JsonResponse
    {
        abort_if($id !== null && ! DB::table('itens_catalogo')->where('id', $id)->exists(), 404, 'Item não encontrado.');
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:30', Rule::unique('itens_catalogo', 'codigo')->ignore($id)],
            'codigo_sufixo' => ['nullable', 'string', 'max:40'],
            'descricao' => ['required', 'string', 'min:3', 'max:500'],
            'nome_resumido' => ['nullable', 'string', 'max:160'],
            'tipo' => ['required', Rule::in(['M', 'S'])],
            'classe_id' => ['required', 'integer', 'exists:classes_materiais,id'],
            'classificacao_natureza_id' => ['nullable', 'integer', 'exists:classificacoes_natureza,id'],
            'subelemento_despesa_id' => ['nullable', 'integer', 'exists:subelementos_despesa,id'],
            'unidade_medida_id' => ['nullable', 'integer', 'exists:unidades_medida,id'],
            'especificacao' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(CatalogoService::STATUS)],
        ]);
        $novo = $s->salvar($id, $dados, $request->user()->id);

        return response()->json($s->detalhe($novo), $id ? 200 : 201);
    }

    public function catalogoPropor(Request $request, CatalogoService $s): JsonResponse
    {
        $d = $request->validate([
            'item_id' => ['required', 'integer', 'exists:itens_catalogo,id'],
            'equivalente_id' => ['required', 'integer', 'exists:itens_catalogo,id'],
            'justificativa' => ['nullable', 'string', 'max:2000'],
        ]);
        $id = $s->proporEquivalencia($d['item_id'], $d['equivalente_id'], $d['justificativa'] ?? null, $request->user()->id);

        return response()->json(['id' => $id, 'equivalencias' => $s->equivalenciasDe($d['item_id'])], 201);
    }

    public function catalogoDecidir(Request $request, int $id, CatalogoService $s): JsonResponse
    {
        $d = $request->validate([
            'decisao' => ['required', Rule::in(['VALIDAR', 'REJEITAR'])],
            'justificativa' => ['required', 'string', 'min:10', 'max:2000'],
            'confirmar_especificacao' => ['boolean'],
        ]);
        $s->decidirEquivalencia($id, $d['decisao'] === 'VALIDAR', $d['justificativa'], (bool) ($d['confirmar_especificacao'] ?? false), $request->user()->id);

        return response()->json(['equivalencias' => $s->equivalenciasDe()]);
    }

    // ---- Análise do total comprado (19)

    public function analiseTotal(Request $request, AnaliseTotalService $s, PrecosService $precos): JsonResponse
    {
        $f = FiltrosAplicados::doRequest($request);

        return response()->json($s->dimensoes() + [
            'unidades' => $s->unidades($f),
            'amparo_classe' => $s->amparoClasse($f),
            'recorrentes' => $s->recorrentes($f),
            'concentracao' => $s->concentracao($f),
            'variacoes_preco' => $precos->painel($f)['maior_dispersao'],
        ]);
    }

    public function decomposicao(Request $request, AnaliseTotalService $s): JsonResponse
    {
        $caminho = AnaliseTotalService::lerCaminho((string) $request->query('caminho', ''));

        return response()->json($s->decomposicao(FiltrosAplicados::doRequest($request), $caminho, (string) $request->query('dimensao', 'ano')));
    }

    public function rankings(Request $request, AnaliseTotalService $s): JsonResponse
    {
        $limites = [];
        foreach (array_keys(AnaliseTotalService::LIMITES) as $k) {
            if (ctype_digit((string) $request->query("limite_$k"))) {
                $limites[$k] = (int) $request->query("limite_$k");
            }
        }

        return response()->json($s->rankings(FiltrosAplicados::doRequest($request), $limites));
    }

    // ---- Análise de preços (24)

    private function janela(Request $request): ?int
    {
        return ctype_digit((string) $request->query('janela_meses')) ? max(0, min(240, (int) $request->query('janela_meses'))) : null;
    }

    public function precos(Request $request, PrecosService $s): JsonResponse
    {
        return response()->json($s->painel(FiltrosAplicados::doRequest($request), $this->janela($request)));
    }

    public function precosBuscar(Request $request, PrecosService $s): JsonResponse
    {
        $termo = mb_substr(trim((string) $request->query('busca', '')), 0, 100);

        return response()->json(['itens' => mb_strlen($termo) >= 2 ? $s->buscarItens(FiltrosAplicados::doRequest($request), $termo) : []]);
    }

    public function precosItem(Request $request, int $id, PrecosService $s): JsonResponse
    {
        $num = fn (string $k) => is_numeric($request->query($k)) ? (float) $request->query($k) : null;
        $criterios = ['quantidade_min' => $num('quantidade_min'), 'quantidade_max' => $num('quantidade_max')];
        if ($request->has('janela_meses')) {
            $criterios['janela_meses'] = $this->janela($request);
        }
        $d = $s->item(FiltrosAplicados::doRequest($request), $id,
            ctype_digit((string) $request->query('unidade_id')) ? (int) $request->query('unidade_id') : null, $criterios);
        abort_if($d === null, 404, 'Item não encontrado.');

        return response()->json($d);
    }
}
