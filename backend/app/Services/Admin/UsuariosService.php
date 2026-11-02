<?php

namespace App\Services\Admin;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Usuários, perfis e permissões (seção 29). Proteções: o usuário não desativa a si mesmo nem tira do
 * próprio perfil a permissão de gerenciar usuários; o Administrador Geral mantém todas as permissões.
 */
class UsuariosService
{
    public function listar(string $busca): array
    {
        $t = '%'.mb_strtolower($busca).'%';

        return User::with(['perfil:id,codigo,nome,restrito_por_orgao', 'orgaos:id,sigla,nome'])
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$t])->orWhereRaw('LOWER(email) LIKE ?', [$t])))
            ->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id, 'nome' => $u->name, 'email' => $u->email, 'ativo' => $u->ativo, 'ultimo_acesso_em' => $u->ultimo_acesso_em?->toDateTimeString(),
                'perfil_id' => $u->perfil_id, 'perfil' => $u->perfil?->nome, 'restrito_por_orgao' => (bool) $u->perfil?->restrito_por_orgao,
                'orgaos' => $u->orgaos->map(fn ($o) => ['id' => $o->id, 'sigla' => $o->sigla, 'nome' => $o->nome])->all(),
            ])->all();
    }

    public function opcoes(): array
    {
        return [
            'perfis' => Perfil::orderBy('id')->get(['id', 'codigo', 'nome', 'restrito_por_orgao'])->all(),
            'orgaos' => DB::table('orgaos')->orderBy('nome')->get(['id', 'sigla', 'nome'])->all(),
        ];
    }

    public function salvar(?int $id, array $entrada, User $autor): int
    {
        $d = validator($entrada, [
            'nome' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($id)],
            'perfil_id' => ['required', 'integer', 'exists:perfis,id'],
            'ativo' => ['boolean'],
            'orgaos' => ['array'], 'orgaos.*' => ['integer', 'exists:orgaos,id'],
            'senha' => [$id ? 'nullable' : 'required', 'string', Password::min(8)->letters()->numbers()],
        ])->validate();

        if ($id === $autor->id && array_key_exists('ativo', $d) && ! $d['ativo']) {
            throw ValidationException::withMessages(['ativo' => 'Você não pode desativar o próprio usuário.']);
        }
        $perfil = Perfil::findOrFail($d['perfil_id']);
        if ($perfil->restrito_por_orgao && empty($d['orgaos'])) {
            throw ValidationException::withMessages(['orgaos' => 'Este perfil é restrito por órgão: escolha ao menos um órgão.']);
        }

        return DB::transaction(function () use ($id, $d, $autor) {
            $u = $id ? User::findOrFail($id) : new User;
            $antes = $id ? (object) $u->only(['name', 'email', 'perfil_id', 'ativo']) : null;
            $u->fill(['name' => $d['nome'], 'email' => $d['email'], 'perfil_id' => $d['perfil_id'], 'ativo' => $d['ativo'] ?? true]);
            if (! empty($d['senha'])) {
                $u->password = $d['senha'];
            }
            $u->save();
            $u->orgaos()->sync($d['orgaos'] ?? []);
            if (! empty($d['senha']) && $id) {
                $u->tokens()->where('id', '!=', $autor->currentAccessToken()?->id ?? 0)->delete();
            }
            CadastrosService::registrar('users', $u->id, $antes, (object) ($u->only(['name', 'email', 'perfil_id', 'ativo']) + ['orgaos' => $d['orgaos'] ?? []]),
                $autor->id, $id ? 'usuarios.editar' : 'usuarios.criar');

            return $u->id;
        });
    }

    public function perfis(): array
    {
        return [
            'permissoes' => Permissao::orderBy('id')->get(['id', 'codigo', 'descricao'])->all(),
            'perfis' => Perfil::with('permissoes:id,codigo')->withCount('usuarios')->orderBy('id')->get()
                ->map(fn ($p) => ['id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'restrito_por_orgao' => (bool) $p->restrito_por_orgao,
                    'usuarios' => $p->usuarios_count, 'permissoes' => $p->permissoes->pluck('codigo')->all()])->all(),
        ];
    }

    /** @param list<string> $codigos */
    public function salvarPermissoes(int $perfilId, array $codigos, User $autor): void
    {
        $perfil = Perfil::with('permissoes')->findOrFail($perfilId);
        if ($perfil->codigo === 'ADMIN') {
            throw ValidationException::withMessages(['permissoes' => 'O Administrador Geral mantém todas as permissões.']);
        }
        if ($autor->perfil_id === $perfil->id && ! in_array('usuarios.gerenciar', $codigos, true)) {
            throw ValidationException::withMessages(['permissoes' => 'Você não pode retirar do próprio perfil a permissão de gerenciar usuários.']);
        }
        $antes = (object) ['permissoes' => $perfil->permissoes->pluck('codigo')->all()];
        $perfil->permissoes()->sync(Permissao::whereIn('codigo', $codigos)->pluck('id'));
        CadastrosService::registrar('perfis', $perfil->id, $antes, (object) ['permissoes' => array_values($codigos)], $autor->id, 'perfis.permissoes');
    }

    public function logs(array $f, int $porPagina, int $pagina): LengthAwarePaginator
    {
        return DB::table('logs_auditoria as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->when($f['acao'] ?? null, fn ($q, $a) => $q->where('l.acao', 'like', $a.'%'))
            ->when($f['usuario_id'] ?? null, fn ($q, $u) => $q->where('l.user_id', $u))
            ->when($f['busca'] ?? null, fn ($q, $b) => $q->where(fn ($w) => $w->where('l.acao', 'like', "%$b%")->orWhere('l.recurso', 'like', "%$b%")->orWhere('u.name', 'like', "%$b%")))
            ->orderByDesc('l.id')
            ->select(['l.id', 'l.acao', 'l.recurso', 'l.recurso_id', 'l.ip', 'l.detalhes', 'l.created_at', 'u.name as usuario'])
            ->paginate($porPagina, ['*'], 'pagina', $pagina);
    }
}
