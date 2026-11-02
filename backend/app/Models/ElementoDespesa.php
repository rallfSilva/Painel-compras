<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElementoDespesa extends Model
{
    protected $table = 'elementos_despesa';

    protected $guarded = ['id'];

    public function natureza(): BelongsTo
    {
        return $this->belongsTo(NaturezaDespesa::class, 'natureza_despesa_id');
    }

    public function subelementos(): HasMany
    {
        return $this->hasMany(SubelementoDespesa::class);
    }
}
