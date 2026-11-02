<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contrato extends Model
{
    protected $table = 'contratos';

    protected $guarded = ['id'];

    protected $casts = [
        'vigencia_inicio' => 'date',
        'vigencia_fim' => 'date',
        'data_assinatura' => 'date',
        'ficticio' => 'boolean',
    ];

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function unidadeCompradora(): BelongsTo
    {
        return $this->belongsTo(UnidadeCompradora::class);
    }
}
