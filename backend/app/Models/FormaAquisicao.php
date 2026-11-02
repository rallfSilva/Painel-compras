<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormaAquisicao extends Model
{
    protected $table = 'formas_aquisicao';

    protected $guarded = ['id'];

    public function sistematica(): BelongsTo
    {
        return $this->belongsTo(Sistematica::class, 'sistematica_id');
    }
}
