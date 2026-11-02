<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrupoMaterial extends Model
{
    protected $table = 'grupos_materiais';

    protected $guarded = ['id'];

    public function classes(): HasMany
    {
        return $this->hasMany(ClasseMaterial::class, 'grupo_id');
    }
}
