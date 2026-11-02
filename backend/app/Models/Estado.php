<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estado extends Model
{
    protected $table = 'estados';

    protected $guarded = ['id'];

    public function regioes(): HasMany
    {
        return $this->hasMany(RegiaoPlanejamento::class);
    }

    public function municipios(): HasMany
    {
        return $this->hasMany(Municipio::class);
    }
}
