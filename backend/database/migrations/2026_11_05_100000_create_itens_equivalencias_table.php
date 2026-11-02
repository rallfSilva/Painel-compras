<?php

use App\Support\Normalizador;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 3B — equivalência entre itens do catálogo, sempre mediante validação técnica (seção 17).
 * Também preenche a descrição normalizada dos itens que ainda não a têm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_equivalencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('itens_catalogo')->cascadeOnDelete();
            $table->foreignId('equivalente_id')->constrained('itens_catalogo')->cascadeOnDelete();
            $table->string('status', 12)->default('PROPOSTA')->index(); // PROPOSTA, VALIDADA, REJEITADA
            $table->text('justificativa')->nullable();
            $table->boolean('especificacoes_diferentes')->default(false);
            $table->foreignId('proposto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validado_em')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'equivalente_id']);
        });

        DB::table('itens_catalogo')->select(['id', 'descricao', 'descricao_normalizada'])->orderBy('id')->chunkById(500, function ($itens) {
            foreach ($itens as $i) {
                $n = Normalizador::descricao($i->descricao);
                if ($n !== $i->descricao_normalizada) {
                    DB::table('itens_catalogo')->where('id', $i->id)->update(['descricao_normalizada' => $n]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_equivalencias');
    }
};
