<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Etapa 3: categoria de alimento no catálogo (seção 13) e benefício a MPE no item (seção 12:
     * compras exclusivas e cotas reservadas, LC 123/2006, art. 48).
     */
    public function up(): void
    {
        Schema::table('itens_catalogo', function (Blueprint $table) {
            // CARNES, AVES, PEIXES, FRUTAS, VERDURAS, LEGUMES, CEREAIS, GRAOS, LATICINIOS, INDUSTRIALIZADOS, OUTROS
            $table->string('categoria_alimento', 30)->nullable()->index();
            $table->boolean('perecivel')->nullable();
        });

        Schema::table('processos_itens', function (Blueprint $table) {
            // NENHUM, EXCLUSIVO (participação exclusiva de MPE) ou COTA_RESERVADA
            $table->string('beneficio_mpe', 20)->default('NENHUM')->index();
        });
    }

    public function down(): void
    {
        Schema::table('processos_itens', fn (Blueprint $t) => $t->dropColumn('beneficio_mpe'));
        Schema::table('itens_catalogo', fn (Blueprint $t) => $t->dropColumn(['categoria_alimento', 'perecivel']));
    }
};
