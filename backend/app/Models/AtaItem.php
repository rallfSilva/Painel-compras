<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtaItem extends Model
{
    protected $table = 'atas_itens';

    protected $guarded = ['id'];

    public function ata(): BelongsTo
    {
        return $this->belongsTo(AtaRegistroPreco::class, 'ata_id');
    }
}
