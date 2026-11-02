<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FiltroController;
use App\Http\Controllers\Api\MetaController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::middleware('permissao:dashboard.ver')->group(function () {
        Route::get('/meta', [MetaController::class, 'meta']);
        Route::get('/glossario', [MetaController::class, 'glossario']);
        Route::get('/filtros/opcoes', [FiltroController::class, 'opcoes']);
        Route::get('/filtros/buscar/{tipo}', [FiltroController::class, 'buscar']);
        Route::get('/dashboard/indicadores', [DashboardController::class, 'indicadores']);
        Route::get('/dashboard/graficos', [DashboardController::class, 'graficos']);
        Route::get('/dashboard/indicadores/{codigo}/registros', [DashboardController::class, 'registros'])
            ->middleware('permissao:registros.ver');
    });
});
