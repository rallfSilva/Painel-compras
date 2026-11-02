<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Orgao extends Model
{
    protected $table = 'orgaos';

    protected $guarded = ['id'];

    public function unidadeSuperior(): BelongsTo
    {
        return $this->belongsTo(UnidadeSuperior::class);
    }

    public function unidades(): HasMany
    {
        return $this->hasMany(UnidadeCompradora::class);
    }
}
