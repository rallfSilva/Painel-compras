<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas da API de entrada (/api/integracao/v1): só aceitam o token de sistema gerado por
 * `php artisan sic:token-integracao`, com a habilidade integracao:enviar e nada além dela.
 * O token de login da interface (habilidade "*") não serve aqui, e o token de sistema não serve
 * nas demais rotas, porque o perfil INTEGRACAO_SISTEMA só tem a permissão integracao.enviar.
 */
class ExigeTokenIntegracao
{
    public const HABILIDADE = 'integracao:enviar';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->abilities !== [self::HABILIDADE] || ! $request->user()->ativo) {
            return response()->json(['message' => 'Use o token de integração do sistema (gerado por php artisan sic:token-integracao).'], 403);
        }

        return $next($request);
    }
}
