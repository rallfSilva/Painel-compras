<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email', 'max:160'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $user = User::with('perfil.permissoes')->where('email', $dados['email'])->first();
        if (! $user || ! $user->ativo || ! Hash::check($dados['password'], $user->password)) {
            $this->auditar(null, 'login_falhou', $request, ['email' => $dados['email']]);
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }

        $user->forceFill(['ultimo_acesso_em' => now()])->save();
        $token = $user->createToken('spa')->plainTextToken;
        $this->auditar($user->id, 'login', $request);

        return response()->json(['token' => $token, 'usuario' => $this->usuario($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['usuario' => $this->usuario($request->user()->load('perfil.permissoes'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->auditar($request->user()->id, 'logout', $request);

        return response()->json(['ok' => true]);
    }

    private function usuario(User $user): array
    {
        return [
            'id' => $user->id,
            'nome' => $user->name,
            'email' => $user->email,
            'perfil' => $user->perfil ? ['codigo' => $user->perfil->codigo, 'nome' => $user->perfil->nome] : null,
            'permissoes' => $user->codigosPermissao(),
        ];
    }

    private function auditar(?int $userId, string $acao, Request $request, array $detalhes = []): void
    {
        DB::table('logs_auditoria')->insert([
            'user_id' => $userId, 'acao' => $acao, 'recurso' => 'auth', 'ip' => $request->ip(),
            'detalhes' => $detalhes ? json_encode($detalhes) : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
