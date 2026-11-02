<?php

use App\Support\Rastreabilidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique(); // ex.: CATMAT, CATSER, ESTADUAL
            $table->string('nome', 160);
            $table->timestamps();
        });

        Schema::create('grupos_materiais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_id')->nullable()->constrained('catalogos')->nullOnDelete();
            $table->char('tipo', 1)->default('M'); // M = material, S = serviço
            $table->string('codigo', 30);
            $table->string('nome', 200);
            Rastreabilidade::colunas($table);
            $table->unique(['catalogo_id', 'codigo']);
        });

        Schema::create('classes_materiais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos_materiais');
            $table->string('codigo', 30);
            $table->string('nome', 200);
            Rastreabilidade::colunas($table);
            $table->unique(['grupo_id', 'codigo']);
        });

        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->string('sigla', 20)->unique();
            $table->string('nome', 80);
            $table->timestamps();
        });

        Schema::create('itens_catalogo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classe_id')->constrained('classes_materiais');
            $table->foreignId('classificacao_natureza_id')->nullable()->constrained('classificacoes_natureza')->nullOnDelete();
            $table->foreignId('subelemento_despesa_id')->nullable()->constrained('subelementos_despesa')->nullOnDelete();
            $table->foreignId('unidade_medida_id')->nullable()->constrained('unidades_medida')->nullOnDelete();
            $table->char('tipo', 1)->default('M');
            $table->string('codigo', 30)->unique();
            $table->string('codigo_sufixo', 40)->nullable(); // código + unidade de fornecimento (ex.: 13722-388)
            $table->string('descricao', 500);
            $table->string('nome_resumido', 160)->nullable();
            $table->string('descricao_normalizada', 500)->nullable()->index();
            $table->text('especificacao')->nullable();
            $table->string('status', 20)->default('ATIVO');
            Rastreabilidade::colunas($table);
        });
    }

    public function down(): void
    {
        foreach (['itens_catalogo', 'unidades_medida', 'classes_materiais', 'grupos_materiais', 'catalogos'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
