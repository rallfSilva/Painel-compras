<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ListagemPaginada;
use App\Http\Controllers\Controller;
use App\Services\Admin\CadastrosService;
use App\Services\Admin\UsuariosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Administração do sistema (seção 29). Cada cadastro exige a permissão própria do recurso. */
class AdminController extends Controller
{
    use ListagemPaginada;

    private function exigir(Request $request, string $recurso): void
    {
        $def = CadastrosService::definicao($recurso);
        abort_unless($request->user()->temPermissao($def['permissao']), 403, 'Sem permissão para este cadastro.');
    }

    public function painel(Request $request, CadastrosService $s): JsonResponse
    {
        $u = $request->user();

        return response()->json([
            'cadastros' => $s->recursos(fn ($p) => $u->temPermissao($p)),
            'usuarios' => $u->temPermissao('usuarios.gerenciar'),
            'logs' => $u->temPermissao('logs.ver'),
            'importacoes' => $u->temPermissao('importacao.executar') || $u->temPermissao('integracoes.gerenciar'),
            'resumo' => [
                'usuarios' => DB::table('users')->count(), 'usuarios_ativos' => DB::table('users')->where('ativo', true)->count(),
                'orgaos' => DB::table('orgaos')->count(), 'unidades' => DB::table('unidades_compradoras')->count(),
                'fornecedores' => DB::table('fornecedores')->count(), 'integracoes_ativas' => DB::table('integracoes')->where('ativa', true)->count(),
            ],
        ]);
    }

    public function listar(Request $request, string $recurso, CadastrosService $s): JsonResponse
    {
        $this->exigir($request, $recurso);

        return $this->respostaPaginada($s->listar($recurso, $this->filtrosLocais($request)['busca'], $this->porPagina($request), $this->pagina($request)),
            ['opcoes' => $request->boolean('com_opcoes') ? $s->opcoes($recurso) : null]);
    }

    public function salvar(Request $request, string $recurso, CadastrosService $s, ?int $id = null): JsonResponse
    {
        $this->exigir($request, $recurso);
        $novo = $s->salvar($recurso, $id, $request->all(), $request->user()->id);

        return response()->json(['id' => $novo], $id ? 200 : 201);
    }

    public function excluir(Request $request, string $recurso, int $id, CadastrosService $s): JsonResponse
    {
        $this->exigir($request, $recurso);
        $s->excluir($recurso, $id, $request->user()->id);

        return response()->json(['ok' => true]);
    }

    public function usuarios(Request $request, UsuariosService $s): JsonResponse
    {
        return response()->json(['usuarios' => $s->listar($this->filtrosLocais($request)['busca'])] + $s->opcoes());
    }

    public function salvarUsuario(Request $request, UsuariosService $s, ?int $id = null): JsonResponse
    {
        return response()->json(['id' => $s->salvar($id, $request->all(), $request->user())], $id ? 200 : 201);
    }

    public function perfis(UsuariosService $s): JsonResponse
    {
        return response()->json($s->perfis());
    }

    public function salvarPerfil(Request $request, int $id, UsuariosService $s): JsonResponse
    {
        $d = $request->validate(['permissoes' => ['present', 'array'], 'permissoes.*' => ['string', 'exists:permissoes,codigo']]);
        $s->salvarPermissoes($id, $d['permissoes'], $request->user());

        return response()->json($s->perfis());
    }

    public function logs(Request $request, UsuariosService $s): JsonResponse
    {
        return $this->respostaPaginada($s->logs([
            'acao' => $request->query('acao'), 'usuario_id' => ctype_digit((string) $request->query('usuario_id')) ? (int) $request->query('usuario_id') : null,
            'busca' => $this->filtrosLocais($request)['busca'] ?: null,
        ], $this->porPagina($request, 50), $this->pagina($request)));
    }
}
