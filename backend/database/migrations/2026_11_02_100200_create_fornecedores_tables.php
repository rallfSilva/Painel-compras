<?php

use App\Support\Rastreabilidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portes_empresariais', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique(); // MEI, ME, EPP, MEDIO, GRANDE, PAF, COOP, PF, NI
            $table->string('nome', 80);
            $table->string('grupo', 10); // MPE, OUTRO, NI (agrupamento simplificado do painel)
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('fornecedores', function (Blueprint $table) {
            $table->id();
            $table->string('documento', 14)->unique(); // CPF (11) ou CNPJ (14), somente dígitos
            $table->char('tipo_pessoa', 1)->default('J'); // J ou F
            $table->string('razao_social', 255);
            $table->string('nome_fantasia', 255)->nullable();
            $table->string('inscricao_estadual', 30)->nullable();
            $table->foreignId('porte_id')->nullable()->constrained('portes_empresariais')->nullOnDelete();
            $table->foreignId('municipio_id')->nullable()->constrained('municipios')->nullOnDelete();
            $table->string('endereco', 255)->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('natureza_juridica', 120)->nullable();
            $table->string('cnae_principal', 10)->nullable();
            $table->json('cnaes_secundarios')->nullable();
            $table->string('situacao_cadastral', 40)->nullable();
            $table->date('data_abertura')->nullable();
            $table->string('email', 160)->nullable();
            $table->string('telefone', 30)->nullable();
            Rastreabilidade::colunas($table);
            $table->index('razao_social');
        });

        Schema::create('fornecedores_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->cascadeOnDelete();
            $table->json('dados_anteriores');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origem', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fornecedores_historico');
        Schema::dropIfExists('fornecedores');
        Schema::dropIfExists('portes_empresariais');
    }
};
