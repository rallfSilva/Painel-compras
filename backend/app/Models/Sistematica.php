<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sistematica extends Model
{
    protected $table = 'sistematicas_aquisicao';

    protected $guarded = ['id'];

    protected $casts = [
        'com_disputa' => 'boolean',
    ];

    public function formas(): HasMany
    {
        return $this->hasMany(FormaAquisicao::class, 'sistematica_id');
    }
}
