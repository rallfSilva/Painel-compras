<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegimeLegal extends Model
{
    protected $table = 'regimes_legais';

    protected $guarded = ['id'];

    public function amparos(): HasMany
    {
        return $this->hasMany(AmparoLegal::class);
    }
}
