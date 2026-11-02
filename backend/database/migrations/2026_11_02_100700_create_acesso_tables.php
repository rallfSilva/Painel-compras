<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfis', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nome', 80);
            $table->string('descricao', 255)->nullable();
            $table->boolean('restrito_por_orgao')->default(false);
            $table->timestamps();
        });

        Schema::create('permissoes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60)->unique(); // ex.: dashboard.ver, importacao.executar
            $table->string('descricao', 160);
            $table->timestamps();
        });

        Schema::create('perfil_permissao', function (Blueprint $table) {
            $table->foreignId('perfil_id')->constrained('perfis')->cascadeOnDelete();
            $table->foreignId('permissao_id')->constrained('permissoes')->cascadeOnDelete();
            $table->primary(['perfil_id', 'permissao_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('perfil_id')->nullable()->after('password')->constrained('perfis')->nullOnDelete();
            $table->boolean('ativo')->default(true)->after('perfil_id');
            $table->timestamp('ultimo_acesso_em')->nullable()->after('ativo');
        });

        // Restrição de acesso por órgão (perfil Gestor de Órgão)
        Schema::create('usuario_orgaos', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('orgao_id')->constrained('orgaos')->cascadeOnDelete();
            $table->primary(['user_id', 'orgao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_orgaos');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perfil_id');
            $table->dropColumn(['ativo', 'ultimo_acesso_em']);
        });
        Schema::dropIfExists('perfil_permissao');
        Schema::dropIfExists('permissoes');
        Schema::dropIfExists('perfis');
    }
};
