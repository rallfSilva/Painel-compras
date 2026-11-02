<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComprasController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FiltroController;
use App\Http\Controllers\Api\FornecedorController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\OrgaoController;
use App\Http\Controllers\Api\ProcessoController;
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

        // Etapa 2 — Compras
        Route::get('/compras/graficos', [ComprasController::class, 'graficos']);
        Route::get('/compras/panorama', [ComprasController::class, 'panorama']);
        Route::get('/orgaos/arvore', [OrgaoController::class, 'arvore']);
        Route::get('/unidades/{id}', [OrgaoController::class, 'unidade'])->whereNumber('id');
        Route::get('/fornecedores/concentracao', [FornecedorController::class, 'concentracao']);

        Route::middleware('permissao:registros.ver')->group(function () {
            Route::get('/processos', [ProcessoController::class, 'index']);
            Route::get('/processos/{id}', [ProcessoController::class, 'show'])->whereNumber('id');
            Route::get('/itens', [ProcessoController::class, 'itens']);
            Route::get('/fornecedores', [FornecedorController::class, 'index']);
            Route::get('/fornecedores/{id}', [FornecedorController::class, 'show'])->whereNumber('id');
        });
    });
});
