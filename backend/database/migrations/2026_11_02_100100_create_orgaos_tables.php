<?php

use App\Support\Rastreabilidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unidade superior = órgão de vinculação (ex.: "ESTADO", secretaria supervisora, fundo).
        Schema::create('unidades_superiores', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->nullable()->unique();
            $table->string('sigla', 30)->nullable();
            $table->string('nome', 200);
            Rastreabilidade::colunas($table);
        });

        Schema::create('orgaos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_superior_id')->nullable()->constrained('unidades_superiores')->nullOnDelete();
            $table->string('codigo', 30)->nullable()->unique();
            $table->string('sigla', 30)->nullable();
            $table->string('nome', 200);
            $table->string('tipo', 30)->default('SECRETARIA'); // SECRETARIA, AUTARQUIA, FUNDACAO, EMPRESA_PUBLICA, OUTRO
            $table->string('cnpj', 14)->nullable();
            $table->boolean('ativo')->default(true);
            Rastreabilidade::colunas($table);
        });

        Schema::create('unidades_compradoras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orgao_id')->constrained('orgaos');
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->nullOnDelete();
            $table->string('codigo', 30)->nullable()->unique();
            $table->string('uasg', 20)->nullable()->index();
            $table->string('nome', 200);
            $table->boolean('ativo')->default(true);
            Rastreabilidade::colunas($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_compradoras');
        Schema::dropIfExists('orgaos');
        Schema::dropIfExists('unidades_superiores');
    }
};
