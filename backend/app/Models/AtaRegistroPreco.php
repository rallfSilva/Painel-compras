<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtaRegistroPreco extends Model
{
    protected $table = 'atas_registro_precos';

    protected $guarded = ['id'];

    protected $casts = [
        'vigencia_inicio' => 'date',
        'vigencia_fim' => 'date',
        'ficticio' => 'boolean',
    ];

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(AtaItem::class, 'ata_id');
    }
}
