<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regimes_legais', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique(); // L8666, L10520, L14133, L11947, OUTRO
            $table->string('nome', 160);
            $table->timestamps();
        });

        Schema::create('amparos_legais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regime_legal_id')->constrained('regimes_legais');
            $table->string('codigo', 60)->unique();
            $table->string('descricao', 255);
            $table->timestamps();
        });

        // Modalidade de licitação (não confundir com contratação direta ou procedimento auxiliar)
        Schema::create('classificacoes_modalidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regime_legal_id')->nullable()->constrained('regimes_legais')->nullOnDelete();
            $table->string('codigo', 40)->unique();
            $table->string('nome', 120);
            $table->timestamps();
        });

        // Sistemática de aquisição: o que o painel de referência chama de "Sistemática"
        Schema::create('sistematicas_aquisicao', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nome', 120);
            // LICITACAO, CONTRATACAO_DIRETA, PROCEDIMENTO_AUXILIAR, CHAMADA_PUBLICA, OUTRO
            $table->string('tipo', 30);
            // Procedimento com disputa de preço? Sem disputa (PNAE, credenciamento, inexigibilidade)
            // é excluído por padrão do % de economia (seção 28).
            $table->boolean('com_disputa')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('formas_aquisicao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sistematica_id')->constrained('sistematicas_aquisicao');
            $table->string('codigo', 40)->unique();
            $table->string('nome', 120);
            $table->timestamps();
        });

        // Natureza / tipo de aquisição (material de consumo, permanente, serviços...)
        Schema::create('classificacoes_natureza', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nome', 120);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        // Hierarquia orçamentária: natureza > elemento > subelemento
        Schema::create('naturezas_despesa', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique(); // ex.: 3.3.90
            $table->string('nome', 160);
            $table->timestamps();
        });

        Schema::create('elementos_despesa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natureza_despesa_id')->constrained('naturezas_despesa');
            $table->string('codigo', 20)->unique(); // ex.: 3.3.90.30
            $table->string('nome', 160);
            $table->timestamps();
        });

        Schema::create('subelementos_despesa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('elemento_despesa_id')->constrained('elementos_despesa');
            $table->string('codigo', 20)->unique(); // ex.: 3.3.90.30.07
            $table->string('nome', 160);
            $table->timestamps();
        });

        // Equivalência entre regimes (8.666/10.520 x 14.133) para séries históricas comparáveis
        Schema::create('equivalencias_regimes', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20); // MODALIDADE ou AMPARO
            $table->unsignedBigInteger('origem_id');
            $table->unsignedBigInteger('equivalente_id');
            $table->string('observacao', 255)->nullable();
            $table->timestamps();
            $table->unique(['tipo', 'origem_id', 'equivalente_id']);
        });
    }

    public function down(): void
    {
        foreach (['equivalencias_regimes', 'subelementos_despesa', 'elementos_despesa', 'naturezas_despesa',
            'classificacoes_natureza', 'formas_aquisicao', 'sistematicas_aquisicao', 'classificacoes_modalidades',
            'amparos_legais', 'regimes_legais'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
