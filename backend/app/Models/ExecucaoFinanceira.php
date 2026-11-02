<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecucaoFinanceira extends Model
{
    protected $table = 'contratos_execucao_financeira';

    protected $guarded = ['id'];

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }
}
