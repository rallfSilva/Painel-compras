<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegiaoPlanejamento extends Model
{
    protected $table = 'regioes_planejamento';

    protected $guarded = ['id'];

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class);
    }

    public function municipios(): HasMany
    {
        return $this->hasMany(Municipio::class);
    }
}
