<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 5 — central de importação (seção 26): arquivo guardado para reprocessamento, colunas lidas,
 * resumo da validação, mensagem de erro e vínculo com a importação reprocessada ou com a sincronização.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importacoes', function (Blueprint $table) {
            $table->string('formato', 10)->nullable()->after('arquivo');
            $table->string('caminho', 255)->nullable()->after('formato');
            $table->unsignedBigInteger('tamanho')->nullable()->after('caminho');
            $table->json('colunas')->nullable()->after('mapeamento');
            $table->json('resumo')->nullable()->after('colunas');
            $table->text('mensagem')->nullable()->after('resumo');
            $table->unsignedInteger('total_duplicados')->default(0)->after('total_rejeitados');
            $table->unsignedBigInteger('reprocessada_de_id')->nullable()->after('mensagem');
            $table->timestamp('iniciada_em')->nullable()->after('reprocessada_de_id');
            $table->index(['tipo', 'status']);
        });
        Schema::table('logs_sincronizacao', function (Blueprint $table) {
            $table->unsignedBigInteger('importacao_id')->nullable()->after('integracao_id');
            $table->foreignId('user_id')->nullable()->after('importacao_id');
        });
    }

    public function down(): void
    {
        Schema::table('logs_sincronizacao', fn (Blueprint $t) => $t->dropColumn(['importacao_id', 'user_id']));
        Schema::table('importacoes', function (Blueprint $table) {
            $table->dropIndex(['tipo', 'status']);
            $table->dropColumn(['formato', 'caminho', 'tamanho', 'colunas', 'resumo', 'mensagem', 'total_duplicados', 'reprocessada_de_id', 'iniciada_em']);
        });
    }
};
