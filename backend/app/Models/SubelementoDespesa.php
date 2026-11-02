<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubelementoDespesa extends Model
{
    protected $table = 'subelementos_despesa';

    protected $guarded = ['id'];

    public function elemento(): BelongsTo
    {
        return $this->belongsTo(ElementoDespesa::class, 'elemento_despesa_id');
    }
}
