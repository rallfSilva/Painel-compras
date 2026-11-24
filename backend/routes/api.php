<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AnaliseController;
use App\Http\Controllers\Api\AssistenteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComprasController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FiltroController;
use App\Http\Controllers\Api\FornecedorController;
use App\Http\Controllers\Api\ImportacaoController;
use App\Http\Controllers\Api\IntegracaoEntradaController;
use App\Http\Controllers\Api\InteligenciaController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\OrgaoController;
use App\Http\Controllers\Api\PlanejamentoController;
use App\Http\Controllers\Api\ProcessoController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// API de entrada para outros sistemas (NexPlanGov e afins). Só o token de integração do sistema acessa.
Route::middleware(['auth:sanctum', 'token.integracao', 'permissao:integracao.enviar', 'throttle:integracao'])->prefix('integracao/v1')->group(function () {
    Route::get('/tipos', [IntegracaoEntradaController::class, 'tipos']);
    Route::post('/lotes', [IntegracaoEntradaController::class, 'receber']);
    Route::get('/lotes/{id}', [IntegracaoEntradaController::class, 'lote'])->whereNumber('id');
    Route::get('/lotes/{id}/erros', [IntegracaoEntradaController::class, 'erros'])->whereNumber('id');
});

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

        // Etapa 4 — PCA, Contratos e Relatórios (seções 20, 22 e 25)
        Route::get('/pca', [PlanejamentoController::class, 'pca']);
        Route::get('/contratos/painel', [PlanejamentoController::class, 'contratos']);
        Route::get('/relatorios', [PlanejamentoController::class, 'relatorios']);
        Route::get('/relatorios/{codigo}', [PlanejamentoController::class, 'relatorio'])->middleware('permissao:relatorios.exportar')->where('codigo', '[a-z_]+');

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
            Route::get('/pca/demandas', [PlanejamentoController::class, 'pcaDemandas']);
            Route::get('/contratos', [PlanejamentoController::class, 'contratosLista']);
            Route::get('/contratos/{id}', [PlanejamentoController::class, 'contrato'])->whereNumber('id');
            Route::get('/inteligencia/itens-adjudicados', [AnaliseController::class, 'itensAdjudicados']);
            Route::get('/inteligencia/precos/itens/{id}', [AnaliseController::class, 'precosItem'])->whereNumber('id');
        });
    });

    // Etapa 4 — Administração (seção 29): cada cadastro confere a permissão do recurso.
    // Etapa 5 — importação de dados e integrações (seção 26).
    Route::middleware('permissao:importacao.executar')->prefix('importacoes')->group(function () {
        Route::get('/tipos', [ImportacaoController::class, 'tipos']);
        Route::get('/', [ImportacaoController::class, 'listar']);
        Route::post('/', [ImportacaoController::class, 'receber'])->middleware('throttle:30,1');
        Route::get('/{id}', [ImportacaoController::class, 'detalhe'])->whereNumber('id');
        Route::get('/{id}/erros', [ImportacaoController::class, 'erros'])->whereNumber('id');
        Route::post('/{id}/validar', [ImportacaoController::class, 'validar'])->whereNumber('id');
        Route::post('/{id}/confirmar', [ImportacaoController::class, 'confirmar'])->whereNumber('id');
        Route::post('/{id}/reprocessar', [ImportacaoController::class, 'reprocessar'])->whereNumber('id');
        Route::delete('/{id}', [ImportacaoController::class, 'excluir'])->whereNumber('id');
    });
    Route::middleware('permissao:integracoes.gerenciar')->prefix('integracoes')->group(function () {
        Route::get('/', [ImportacaoController::class, 'integracoes']);
        Route::put('/{codigo}', [ImportacaoController::class, 'salvarIntegracao'])->where('codigo', '[A-Za-z_]+');
        Route::post('/{codigo}/testar', [ImportacaoController::class, 'testarIntegracao'])->where('codigo', '[A-Za-z_]+')->middleware('throttle:20,1');
        Route::post('/{codigo}/sincronizar', [ImportacaoController::class, 'sincronizar'])->where('codigo', '[A-Za-z_]+')->middleware('throttle:10,1');
    });

    // Etapa 6 — assistente em linguagem natural, alertas e recomendações (seção 32).
    Route::middleware('permissao:ia.consultar')->prefix('ia')->group(function () {
        Route::get('/', [AssistenteController::class, 'configuracao']);
        Route::post('/perguntar', [AssistenteController::class, 'perguntar'])->middleware('throttle:30,1');
        Route::get('/alertas', [AssistenteController::class, 'alertas']);
    });

    Route::prefix('admin')->group(function () {
        Route::get('/', [AdminController::class, 'painel']);
        Route::get('/cadastros/{recurso}', [AdminController::class, 'listar']);
        Route::post('/cadastros/{recurso}', [AdminController::class, 'salvar']);
        Route::put('/cadastros/{recurso}/{id}', [AdminController::class, 'salvar'])->whereNumber('id');
        Route::delete('/cadastros/{recurso}/{id}', [AdminController::class, 'excluir'])->whereNumber('id');
        Route::middleware('permissao:usuarios.gerenciar')->group(function () {
            Route::get('/usuarios', [AdminController::class, 'usuarios']);
            Route::post('/usuarios', [AdminController::class, 'salvarUsuario']);
            Route::put('/usuarios/{id}', [AdminController::class, 'salvarUsuario'])->whereNumber('id');
            Route::get('/perfis', [AdminController::class, 'perfis']);
            Route::put('/perfis/{id}', [AdminController::class, 'salvarPerfil'])->whereNumber('id');
        });
        Route::get('/logs', [AdminController::class, 'logs'])->middleware('permissao:logs.ver');
    });
});
