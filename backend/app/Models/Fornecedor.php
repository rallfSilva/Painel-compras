<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fornecedor extends Model
{
    protected $table = 'fornecedores';

    protected $guarded = ['id'];

    public function porte(): BelongsTo
    {
        return $this->belongsTo(PorteEmpresarial::class, 'porte_id');
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }
}
