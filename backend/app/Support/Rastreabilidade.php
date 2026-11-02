<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Colunas de rastreabilidade comuns a todas as tabelas de dados (seção 27 do prompt):
 * origem do registro, importação de onde veio, marca de dado fictício e data de atualização na fonte.
 */
class Rastreabilidade
{
    public const ORIGEM_MANUAL = 'MANUAL';
    public const ORIGEM_IMPORTACAO = 'IMPORTACAO';
    public const ORIGEM_PNCP = 'PNCP';
    public const ORIGEM_COMPRASGOV = 'COMPRASGOV';
    public const ORIGEM_SISTEMA_ESTADUAL = 'SISTEMA_ESTADUAL';
    public const ORIGEM_DEMONSTRATIVO = 'DEMONSTRATIVO';

    public static function colunas(Blueprint $table): void
    {
        $table->string('origem', 30)->default(self::ORIGEM_MANUAL)->index();
        $table->unsignedBigInteger('importacao_id')->nullable()->index();
        $table->boolean('ficticio')->default(false)->index();
        $table->timestamp('fonte_atualizado_em')->nullable();
        $table->timestamps();
    }
}
