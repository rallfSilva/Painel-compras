<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Municipio extends Model
{
    protected $table = 'municipios';

    protected $guarded = ['id'];

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class);
    }

    public function regiao(): BelongsTo
    {
        return $this->belongsTo(RegiaoPlanejamento::class, 'regiao_planejamento_id');
    }
}
