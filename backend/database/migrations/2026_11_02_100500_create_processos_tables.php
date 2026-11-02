<?php

use App\Support\Rastreabilidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Processo de contratação. Representa licitações, contratações diretas, chamadas públicas e
         * adesões. As tabelas "licitacoes" e "licitacoes_resultados" do prompt foram unificadas aqui
         * e em processos_itens, para não duplicar valores (ver docs/ARQUITETURA.md).
         */
        Schema::create('processos', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 120)->unique(); // chave natural da fonte, evita duplicidade
            $table->string('numero_publicacao', 30)->nullable()->index();
            $table->string('numero_processo_sei', 40)->nullable()->index();
            $table->string('numero_processo_adm', 40)->nullable();
            $table->string('numero_licitacao', 40)->nullable();
            $table->unsignedSmallInteger('ano')->index();
            $table->unsignedTinyInteger('mes');
            $table->unsignedTinyInteger('trimestre');
            $table->unsignedTinyInteger('semestre');
            $table->date('data_abertura')->index();
            $table->date('data_publicacao')->nullable();
            $table->date('data_sessao')->nullable();
            $table->date('data_adjudicacao')->nullable();
            $table->date('data_homologacao')->nullable();
            $table->foreignId('unidade_compradora_id')->constrained('unidades_compradoras');
            $table->text('objeto');
            $table->text('descricao')->nullable();
            $table->foreignId('regime_legal_id')->nullable()->constrained('regimes_legais')->nullOnDelete();
            $table->foreignId('modalidade_id')->nullable()->constrained('classificacoes_modalidades')->nullOnDelete();
            $table->foreignId('sistematica_id')->constrained('sistematicas_aquisicao');
            $table->foreignId('forma_aquisicao_id')->nullable()->constrained('formas_aquisicao')->nullOnDelete();
            $table->foreignId('amparo_legal_id')->nullable()->constrained('amparos_legais')->nullOnDelete();
            $table->foreignId('classificacao_natureza_id')->nullable()->constrained('classificacoes_natureza')->nullOnDelete();
            $table->string('forma_disputa', 40)->nullable();
            $table->string('criterio_julgamento', 60)->nullable();
            $table->boolean('eletronico')->default(true);
            $table->boolean('registro_precos')->default(false)->index();
            $table->boolean('julgamento_lote')->default(false);
            // EM_ANDAMENTO, CONCLUIDO, DESERTO, FRACASSADO, REVOGADO, ANULADO, SUSPENSO
            $table->string('situacao', 20)->default('CONCLUIDO')->index();
            $table->string('fase_atual', 30)->nullable();
            $table->decimal('valor_estimado', 18, 2)->nullable();
            $table->decimal('valor_adjudicado', 18, 2)->nullable();
            $table->decimal('valor_homologado', 18, 2)->nullable();
            $table->decimal('valor_contratado', 18, 2)->nullable();
            $table->unsignedInteger('quantidade_participantes')->nullable();
            $table->string('responsavel', 160)->nullable();
            $table->string('agente_contratacao', 160)->nullable();
            $table->string('link_pncp', 500)->nullable();
            $table->string('link_edital', 500)->nullable();
            Rastreabilidade::colunas($table);

            $table->index(['ano', 'mes']);
            $table->index(['unidade_compradora_id', 'ano']);
            $table->index(['sistematica_id', 'ano']);
        });

        Schema::create('processos_fases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->string('fase', 30); // PLANEJAMENTO, PREPARATORIA, DIVULGACAO, PROPOSTAS, JULGAMENTO, HABILITACAO, RECURSOS, ADJUDICACAO, HOMOLOGACAO, CONTRATACAO, EXECUCAO
            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();
            $table->string('observacao', 500)->nullable();
            $table->timestamps();
        });

        /*
         * Item do processo, com os valores de cada etapa mantidos em colunas separadas
         * (estimado, adjudicado, homologado, contratado). Equivale a "itens adjudicados".
         */
        Schema::create('processos_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->unsignedInteger('numero_item');
            $table->string('lote', 20)->nullable();
            $table->foreignId('item_catalogo_id')->nullable()->constrained('itens_catalogo')->nullOnDelete();
            $table->string('codigo_material_sufixo', 40)->nullable();
            $table->string('descricao', 500);
            $table->foreignId('unidade_medida_id')->nullable()->constrained('unidades_medida')->nullOnDelete();
            $table->foreignId('subelemento_despesa_id')->nullable()->constrained('subelementos_despesa')->nullOnDelete();
            $table->decimal('quantidade_estimada', 18, 4)->nullable();
            $table->decimal('quantidade_adjudicada', 18, 4)->nullable();
            $table->decimal('valor_unitario_estimado', 18, 4)->nullable();
            $table->decimal('valor_unitario_adjudicado', 18, 4)->nullable();
            $table->decimal('valor_total_estimado', 18, 2)->nullable();
            $table->decimal('valor_total_adjudicado', 18, 2)->nullable();
            $table->decimal('valor_total_homologado', 18, 2)->nullable();
            $table->decimal('valor_total_contratado', 18, 2)->nullable();
            $table->foreignId('fornecedor_id')->nullable()->constrained('fornecedores')->nullOnDelete();
            $table->date('data_adjudicacao')->nullable();
            // ADJUDICADO, HOMOLOGADO, CONTRATADO, DESERTO, FRACASSADO, CANCELADO
            $table->string('situacao', 20)->default('HOMOLOGADO');
            Rastreabilidade::colunas($table);

            $table->unique(['processo_id', 'numero_item']);
            $table->index('fornecedor_id');
            $table->index('item_catalogo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processos_itens');
        Schema::dropIfExists('processos_fases');
        Schema::dropIfExists('processos');
    }
};
