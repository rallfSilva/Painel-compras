<?php

use App\Support\Rastreabilidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Participantes de cada processo (licitantes), para medir participação e vitórias dos
     * fornecedores (seção 23). O vencedor de cada item continua em processos_itens.fornecedor_id.
     */
    public function up(): void
    {
        Schema::create('processos_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->foreignId('fornecedor_id')->constrained('fornecedores');
            $table->boolean('vencedor')->default(false);
            $table->decimal('valor_proposta', 18, 2)->nullable();
            $table->boolean('habilitado')->nullable();
            Rastreabilidade::colunas($table);

            $table->unique(['processo_id', 'fornecedor_id']);
            $table->index('fornecedor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processos_participantes');
    }
};
