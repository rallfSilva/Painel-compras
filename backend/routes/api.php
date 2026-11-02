<?php

use App\Http\Controllers\Api\AnaliseController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComprasController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FiltroController;
use App\Http\Controllers\Api\FornecedorController;
use App\Http\Controllers\Api\InteligenciaController;
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

        // Etapa 3A — Inteligência (seções 9 a 15)
        Route::get('/inteligencia/mpe', [InteligenciaController::class, 'mpe']);
        Route::get('/inteligencia/alimentos', [InteligenciaController::class, 'alimentos']);
        Route::get('/inteligencia/licitacoes-rp', [InteligenciaController::class, 'licitacoesRp']);
        Route::get('/inteligencia/aquisicoes-rp', [InteligenciaController::class, 'aquisicoesRp']);
        Route::get('/inteligencia/geografico', [InteligenciaController::class, 'geografico']);
        Route::get('/inteligencia/municipios/{id}', [InteligenciaController::class, 'municipio'])->whereNumber('id');
        Route::get('/inteligencia/natureza', [InteligenciaController::class, 'natureza']);
        Route::get('/inteligencia/sistematica', [InteligenciaController::class, 'sistematica']);

        // Etapa 3B — Inteligência (seções 16 a 19 e 24)
        Route::get('/inteligencia/classes', [AnaliseController::class, 'classes']);
        Route::get('/inteligencia/classes/{id}', [AnaliseController::class, 'classe'])->whereNumber('id');
        Route::get('/catalogo', [AnaliseController::class, 'catalogo']);
        Route::get('/catalogo/resumo', [AnaliseController::class, 'catalogoResumo']);
        Route::get('/catalogo/duplicidades', [AnaliseController::class, 'catalogoDuplicidades']);
        Route::get('/catalogo/equivalencias', [AnaliseController::class, 'catalogoEquivalencias']);
        Route::get('/catalogo/{id}', [AnaliseController::class, 'catalogoItem'])->whereNumber('id');
        Route::get('/inteligencia/analise-total', [AnaliseController::class, 'analiseTotal']);
        Route::get('/inteligencia/analise-total/decomposicao', [AnaliseController::class, 'decomposicao']);
        Route::get('/inteligencia/analise-total/rankings', [AnaliseController::class, 'rankings']);
        Route::get('/inteligencia/precos', [AnaliseController::class, 'precos']);
        Route::get('/inteligencia/precos/itens', [AnaliseController::class, 'precosBuscar']);

        Route::middleware('permissao:cadastros.gerenciar')->group(function () {
            Route::post('/catalogo', [AnaliseController::class, 'catalogoSalvar']);
            Route::put('/catalogo/{id}', [AnaliseController::class, 'catalogoSalvar'])->whereNumber('id');
            Route::post('/catalogo/equivalencias', [AnaliseController::class, 'catalogoPropor']);
            Route::post('/catalogo/equivalencias/{id}/decisao', [AnaliseController::class, 'catalogoDecidir'])->whereNumber('id');
        });

        Route::middleware('permissao:registros.ver')->group(function () {
            Route::get('/processos', [ProcessoController::class, 'index']);
            Route::get('/processos/{id}', [ProcessoController::class, 'show'])->whereNumber('id');
            Route::get('/itens', [ProcessoController::class, 'itens']);
            Route::get('/fornecedores', [FornecedorController::class, 'index']);
            Route::get('/fornecedores/{id}', [FornecedorController::class, 'show'])->whereNumber('id');
            Route::get('/inteligencia/licitacoes-rp/processos', [InteligenciaController::class, 'licitacoesRpProcessos']);
            Route::get('/inteligencia/itens/{id}', [InteligenciaController::class, 'item'])->whereNumber('id');
            Route::get('/inteligencia/itens-adjudicados', [AnaliseController::class, 'itensAdjudicados']);
            Route::get('/inteligencia/precos/itens/{id}', [AnaliseController::class, 'precosItem'])->whereNumber('id');
        });
    });
});
