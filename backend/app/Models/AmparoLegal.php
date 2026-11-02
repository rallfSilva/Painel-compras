<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmparoLegal extends Model
{
    protected $table = 'amparos_legais';

    protected $guarded = ['id'];

    public function regime(): BelongsTo
    {
        return $this->belongsTo(RegimeLegal::class, 'regime_legal_id');
    }
}
