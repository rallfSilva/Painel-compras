<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Processo extends Model
{
    protected $table = 'processos';

    protected $guarded = ['id'];

    protected $casts = [
        'data_abertura' => 'date',
        'data_publicacao' => 'date',
        'data_sessao' => 'date',
        'data_adjudicacao' => 'date',
        'data_homologacao' => 'date',
        'eletronico' => 'boolean',
        'registro_precos' => 'boolean',
        'julgamento_lote' => 'boolean',
        'ficticio' => 'boolean',
    ];

    public function unidadeCompradora(): BelongsTo
    {
        return $this->belongsTo(UnidadeCompradora::class);
    }

    public function sistematica(): BelongsTo
    {
        return $this->belongsTo(Sistematica::class, 'sistematica_id');
    }

    public function formaAquisicao(): BelongsTo
    {
        return $this->belongsTo(FormaAquisicao::class);
    }

    public function modalidade(): BelongsTo
    {
        return $this->belongsTo(Modalidade::class, 'modalidade_id');
    }

    public function amparoLegal(): BelongsTo
    {
        return $this->belongsTo(AmparoLegal::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ProcessoItem::class);
    }
}
