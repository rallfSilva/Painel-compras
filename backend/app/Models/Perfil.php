<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Perfil extends Model
{
    protected $table = 'perfis';

    protected $guarded = ['id'];

    protected $casts = [
        'restrito_por_orgao' => 'boolean',
    ];

    public function permissoes(): BelongsToMany
    {
        return $this->belongsToMany(Permissao::class, 'perfil_permissao');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
