<?php

namespace App\Providers;

use App\Services\Configuracoes;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Configuracoes::class);
    }

    public function boot(): void
    {
        // Proteção contra força bruta no login: 5 tentativas por minuto por e-mail + IP
        // API de entrada de outros sistemas: 60 lotes por minuto por token de integração
        RateLimiter::for('integracao', fn (Request $request) => Limit::perMinute(60)->by('integracao|'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
