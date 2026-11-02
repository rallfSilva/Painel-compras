<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso nas rotas: ->middleware('permissao:dashboard.ver') */
class ExigePermissao
{
    public function handle(Request $request, Closure $next, string ...$permissoes): Response
    {
        $user = $request->user();
        if (! $user || $user->ativo === false) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }
        foreach ($permissoes as $p) {
            if (! $user->temPermissao($p)) {
                return response()->json(['message' => 'Você não tem permissão para esta ação.'], 403);
            }
        }

        return $next($request);
    }
}
