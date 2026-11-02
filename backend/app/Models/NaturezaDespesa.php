<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NaturezaDespesa extends Model
{
    protected $table = 'naturezas_despesa';

    protected $guarded = ['id'];

    public function elementos(): HasMany
    {
        return $this->hasMany(ElementoDespesa::class);
    }
}
