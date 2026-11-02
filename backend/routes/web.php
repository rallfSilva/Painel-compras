<?php

use Illuminate\Support\Facades\Route;

/*
 * O frontend React é compilado para public/app (npm run build na pasta frontend).
 * Qualquer rota que não seja da API devolve o index.html da SPA, para o roteamento no navegador.
 */
Route::get('/{caminho?}', function () {
    $index = public_path('app/index.html');
    if (! file_exists($index)) {
        return response('Frontend não compilado. Rode "npm run build" na pasta frontend. Veja docs/INSTALACAO_XAMPP.md.', 503)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    return response()->file($index, ['Content-Type' => 'text/html; charset=UTF-8']);
})->where('caminho', '^(?!api|up|app/).*$');
