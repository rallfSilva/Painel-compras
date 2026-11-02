<?php

use App\Support\Rastreabilidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 4 — Plano de Contratações Anual (seção 20 e tabelas de planejamento da seção 27).
 * pca = plano do órgão no exercício; pca_demandas = cada contratação planejada; pca_itens = itens
 * do catálogo previstos na demanda; pca_execucao = vínculo da demanda com os processos que a executam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pca', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('exercicio')->index();
            $table->foreignId('orgao_id')->constrained('orgaos');
            $table->string('situacao', 20)->default('ELABORACAO'); // ELABORACAO, APROVADO, PUBLICADO, REVISADO
            $table->date('data_aprovacao')->nullable();
            $table->string('link_pncp', 500)->nullable();
            Rastreabilidade::colunas($table);
            $table->unique(['exercicio', 'orgao_id']);
        });

        Schema::create('pca_demandas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pca_id')->constrained('pca')->cascadeOnDelete();
            $table->foreignId('unidade_requisitante_id')->nullable()->constrained('unidades_compradoras')->nullOnDelete();
            $table->string('numero_demanda', 40);
            $table->string('codigo_contratacao', 60)->nullable();
            $table->string('objeto', 500);
            $table->text('descricao_necessidade')->nullable();
            $table->string('categoria', 20)->default('MATERIAL'); // MATERIAL, SERVICO, OBRA, TIC
            $table->foreignId('grupo_id')->nullable()->constrained('grupos_materiais')->nullOnDelete();
            $table->foreignId('classe_id')->nullable()->constrained('classes_materiais')->nullOnDelete();
            $table->decimal('quantidade_estimada', 18, 4)->nullable();
            $table->decimal('valor_estimado', 18, 2);
            $table->date('data_pretendida')->nullable()->index();
            $table->string('prioridade', 10)->default('MEDIA'); // ALTA, MEDIA, BAIXA
            $table->string('situacao_planejamento', 20)->default('PLANEJADA'); // PLANEJADA, APROVADA, REPROGRAMADA, EXCLUIDA
            $table->string('responsavel', 160)->nullable();
            $table->string('processo_sei', 60)->nullable();
            $table->string('situacao_execucao', 20)->default('NAO_INICIADA')->index(); // NAO_INICIADA, EM_ANDAMENTO, CONCLUIDA, CANCELADA
            Rastreabilidade::colunas($table);
        });

        Schema::create('pca_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demanda_id')->constrained('pca_demandas')->cascadeOnDelete();
            $table->foreignId('item_catalogo_id')->nullable()->constrained('itens_catalogo')->nullOnDelete();
            $table->foreignId('unidade_medida_id')->nullable()->constrained('unidades_medida')->nullOnDelete();
            $table->string('descricao', 500)->nullable();
            $table->decimal('quantidade', 18, 4)->nullable();
            $table->decimal('valor_unitario_estimado', 18, 4)->nullable();
            $table->decimal('valor_total_estimado', 18, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pca_execucao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demanda_id')->constrained('pca_demandas')->cascadeOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['demanda_id', 'processo_id']);
        });
    }

    public function down(): void
    {
        foreach (['pca_execucao', 'pca_itens', 'pca_demandas', 'pca'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
