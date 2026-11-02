<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemCatalogo extends Model
{
    protected $table = 'itens_catalogo';

    protected $guarded = ['id'];

    public function classe(): BelongsTo
    {
        return $this->belongsTo(ClasseMaterial::class, 'classe_id');
    }
}
