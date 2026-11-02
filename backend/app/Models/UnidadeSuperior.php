<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnidadeSuperior extends Model
{
    protected $table = 'unidades_superiores';

    protected $guarded = ['id'];

    public function orgaos(): HasMany
    {
        return $this->hasMany(Orgao::class);
    }
}
