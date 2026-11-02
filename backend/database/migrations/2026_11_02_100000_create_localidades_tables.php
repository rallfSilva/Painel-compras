<?php

use App\Support\Rastreabilidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estados', function (Blueprint $table) {
            $table->id();
            $table->char('uf', 2)->unique();
            $table->string('nome', 60);
            $table->string('codigo_ibge', 2)->unique();
            $table->string('regiao_pais', 20)->nullable(); // Norte, Nordeste...
            $table->timestamps();
        });

        Schema::create('regioes_planejamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estado_id')->constrained('estados');
            $table->string('nome', 120);
            $table->string('codigo', 20)->nullable();
            Rastreabilidade::colunas($table);
            $table->unique(['estado_id', 'nome']);
        });

        Schema::create('municipios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('regiao_planejamento_id')->nullable()->constrained('regioes_planejamento')->nullOnDelete();
            $table->string('codigo_ibge', 7)->unique();
            $table->string('nome', 120);
            $table->timestamps();
            $table->index(['estado_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
        Schema::dropIfExists('regioes_planejamento');
        Schema::dropIfExists('estados');
    }
};
