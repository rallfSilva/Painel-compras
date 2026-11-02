<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnidadeCompradora extends Model
{
    protected $table = 'unidades_compradoras';

    protected $guarded = ['id'];

    public function orgao(): BelongsTo
    {
        return $this->belongsTo(Orgao::class);
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }
}
