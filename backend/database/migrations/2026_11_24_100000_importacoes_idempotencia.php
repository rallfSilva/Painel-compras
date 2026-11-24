<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API de entrada para outros sistemas (NexPlanGov e afins): chave de idempotência do lote enviado,
 * para que um reenvio do mesmo lote (falha de rede, nova tentativa) não gere uma segunda importação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importacoes', function (Blueprint $table) {
            $table->string('chave_idempotencia', 100)->nullable()->after('origem');
            $table->unique(['user_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::table('importacoes', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'chave_idempotencia']);
            $table->dropColumn('chave_idempotencia');
        });
    }
};
