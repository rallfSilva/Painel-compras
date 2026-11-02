<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glossario_indicadores', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('nome', 120);
            $table->text('descricao');
            $table->text('formula');
            $table->string('unidade', 20); // BRL, PERCENTUAL, QUANTIDADE
            $table->string('fonte', 160);
            $table->string('data_referencia', 160);
            $table->text('criterio_inclusao')->nullable();
            $table->text('criterio_exclusao')->nullable();
            $table->timestamps();
        });

        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 80)->unique();
            $table->text('valor')->nullable();
            $table->string('descricao', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('importacoes', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 40); // PROCESSOS, CONTRATOS, FORNECEDORES, ...
            $table->string('arquivo', 255)->nullable();
            $table->string('origem', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('PENDENTE'); // PENDENTE, VALIDADO, PROCESSANDO, CONCLUIDO, ERRO
            $table->unsignedInteger('total_registros')->default(0);
            $table->unsignedInteger('total_importados')->default(0);
            $table->unsignedInteger('total_rejeitados')->default(0);
            $table->json('mapeamento')->nullable();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();
        });

        Schema::create('importacoes_erros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacao_id')->constrained('importacoes')->cascadeOnDelete();
            $table->unsignedInteger('linha')->nullable();
            $table->string('campo', 80)->nullable();
            $table->text('mensagem');
            $table->json('registro')->nullable();
            $table->timestamps();
        });

        Schema::create('integracoes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique(); // PNCP, COMPRASGOV, FINANCEIRO_RR
            $table->string('nome', 120);
            $table->boolean('ativa')->default(false);
            $table->json('parametros')->nullable();
            $table->timestamp('ultima_sincronizacao_em')->nullable();
            $table->timestamps();
        });

        Schema::create('logs_sincronizacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integracao_id')->constrained('integracoes')->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('registros_recebidos')->default(0);
            $table->unsignedInteger('registros_gravados')->default(0);
            $table->text('mensagem')->nullable();
            $table->timestamp('iniciado_em');
            $table->timestamp('finalizado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('logs_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acao', 60);
            $table->string('recurso', 80)->nullable();
            $table->unsignedBigInteger('recurso_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('detalhes')->nullable();
            $table->timestamps();
            $table->index(['recurso', 'recurso_id']);
        });

        Schema::create('historico_alteracoes', function (Blueprint $table) {
            $table->id();
            $table->string('tabela', 80);
            $table->unsignedBigInteger('registro_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('antes')->nullable();
            $table->json('depois')->nullable();
            $table->timestamps();
            $table->index(['tabela', 'registro_id']);
        });

        Schema::create('arquivos_anexos', function (Blueprint $table) {
            $table->id();
            $table->string('referencia_tipo', 80);
            $table->unsignedBigInteger('referencia_id');
            $table->string('nome', 255);
            $table->string('caminho', 500);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['referencia_tipo', 'referencia_id']);
        });

        Schema::create('consultas_salvas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nome', 120);
            $table->string('rota', 120);
            $table->json('filtros');
            $table->timestamps();
        });

        // Resumo mensal para séries históricas e painéis de alto volume, recalculado após cada carga.
        Schema::create('agregados_compras_mensal', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('ano');
            $table->unsignedTinyInteger('mes');
            $table->unsignedBigInteger('unidade_compradora_id');
            $table->unsignedBigInteger('sistematica_id');
            $table->unsignedBigInteger('porte_id')->nullable();
            $table->unsignedBigInteger('classe_id')->nullable();
            $table->boolean('registro_precos');
            $table->unsignedInteger('qtd_itens');
            $table->decimal('valor_estimado', 20, 2)->default(0);
            $table->decimal('valor_adjudicado', 20, 2)->default(0);
            $table->decimal('valor_homologado', 20, 2)->default(0);
            $table->decimal('valor_contratado', 20, 2)->default(0);
            $table->timestamp('calculado_em');
            $table->index(['ano', 'mes']);
            $table->index(['unidade_compradora_id', 'ano']);
        });
    }

    public function down(): void
    {
        foreach (['agregados_compras_mensal', 'consultas_salvas', 'arquivos_anexos', 'historico_alteracoes',
            'logs_auditoria', 'logs_sincronizacao', 'integracoes', 'importacoes_erros', 'importacoes',
            'configuracoes', 'glossario_indicadores'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
