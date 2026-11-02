<?php

use App\Support\Rastreabilidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 120)->unique();
            $table->string('numero', 40);
            $table->foreignId('processo_id')->nullable()->constrained('processos')->nullOnDelete();
            $table->unsignedBigInteger('ata_id')->nullable()->index();
            $table->foreignId('unidade_compradora_id')->constrained('unidades_compradoras');
            $table->foreignId('fornecedor_id')->constrained('fornecedores');
            $table->text('objeto');
            $table->decimal('valor_inicial', 18, 2);
            $table->decimal('valor_atualizado', 18, 2)->nullable();
            $table->date('data_assinatura')->nullable();
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim')->index();
            $table->string('situacao', 20)->default('VIGENTE')->index(); // VIGENTE, ENCERRADO, RESCINDIDO, SUSPENSO
            $table->string('gestor', 160)->nullable();
            $table->string('fiscal', 160)->nullable();
            $table->string('link_pncp', 500)->nullable();
            Rastreabilidade::colunas($table);
        });

        Schema::create('contratos_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->foreignId('processo_item_id')->nullable()->constrained('processos_itens')->nullOnDelete();
            $table->decimal('quantidade', 18, 4);
            $table->decimal('valor_unitario', 18, 4);
            $table->decimal('valor_total', 18, 2);
            $table->timestamps();
        });

        Schema::create('contratos_aditivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->string('numero', 30);
            $table->string('tipo', 20); // PRAZO, VALOR, OBJETO, MISTO, APOSTILAMENTO
            $table->decimal('valor', 18, 2)->nullable();
            $table->date('nova_vigencia_fim')->nullable();
            $table->date('data_assinatura')->nullable();
            $table->text('justificativa')->nullable();
            Rastreabilidade::colunas($table);
        });

        // Empenho, liquidação e pagamento. Sem dados aqui, os indicadores mostram "Não informado".
        Schema::create('contratos_execucao_financeira', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->nullOnDelete();
            $table->string('tipo', 12); // EMPENHO, LIQUIDACAO, PAGAMENTO
            $table->string('documento', 40)->nullable();
            $table->date('data');
            $table->decimal('valor', 18, 2);
            Rastreabilidade::colunas($table);
            $table->index(['tipo', 'data']);
        });

        Schema::create('atas_registro_precos', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 120)->unique();
            $table->string('numero', 40);
            $table->string('numero_publicacao', 30)->nullable()->index();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->nullOnDelete();
            $table->foreignId('orgao_gerenciador_id')->constrained('unidades_compradoras');
            $table->text('objeto');
            $table->date('data_assinatura')->nullable();
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim')->index();
            $table->string('situacao', 20)->default('VIGENTE')->index(); // VIGENTE, EXPIRADA, CANCELADA
            $table->decimal('valor_estimado', 18, 2)->nullable();
            $table->decimal('valor_registrado', 18, 2)->nullable();
            Rastreabilidade::colunas($table);
        });

        Schema::create('atas_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ata_id')->constrained('atas_registro_precos')->cascadeOnDelete();
            $table->foreignId('processo_item_id')->nullable()->constrained('processos_itens')->nullOnDelete();
            $table->foreignId('item_catalogo_id')->nullable()->constrained('itens_catalogo')->nullOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores');
            $table->decimal('quantidade_registrada', 18, 4);
            $table->decimal('valor_unitario_estimado', 18, 4)->nullable();
            $table->decimal('valor_unitario_registrado', 18, 4);
            $table->timestamps();
        });

        Schema::create('atas_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ata_id')->constrained('atas_registro_precos')->cascadeOnDelete();
            $table->foreignId('ata_item_id')->nullable()->constrained('atas_itens')->cascadeOnDelete();
            $table->foreignId('unidade_compradora_id')->constrained('unidades_compradoras');
            $table->string('tipo', 12)->default('PARTICIPANTE'); // GERENCIADOR, PARTICIPANTE, ADERENTE
            $table->decimal('quantidade_prevista', 18, 4)->nullable();
            $table->date('data_adesao')->nullable();
            $table->timestamps();
        });

        Schema::create('atas_utilizacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ata_item_id')->constrained('atas_itens')->cascadeOnDelete();
            $table->foreignId('unidade_compradora_id')->constrained('unidades_compradoras');
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->decimal('quantidade', 18, 4);
            $table->decimal('valor_total', 18, 2);
            $table->date('data');
            Rastreabilidade::colunas($table);
        });
    }

    public function down(): void
    {
        foreach (['atas_utilizacoes', 'atas_participantes', 'atas_itens', 'atas_registro_precos',
            'contratos_execucao_financeira', 'contratos_aditivos', 'contratos_itens', 'contratos'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
