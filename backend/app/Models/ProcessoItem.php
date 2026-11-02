<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessoItem extends Model
{
    protected $table = 'processos_itens';

    protected $guarded = ['id'];

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function itemCatalogo(): BelongsTo
    {
        return $this->belongsTo(ItemCatalogo::class);
    }
}
