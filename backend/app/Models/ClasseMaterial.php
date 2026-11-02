<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClasseMaterial extends Model
{
    protected $table = 'classes_materiais';

    protected $guarded = ['id'];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoMaterial::class, 'grupo_id');
    }
}
