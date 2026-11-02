<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'perfil_id', 'ativo'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'ultimo_acesso_em' => 'datetime',
            'password' => 'hashed',
            'ativo' => 'boolean',
        ];
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    public function orgaos(): BelongsToMany
    {
        return $this->belongsToMany(Orgao::class, 'usuario_orgaos');
    }

    /** @return list<string> */
    public function codigosPermissao(): array
    {
        return $this->perfil?->permissoes->pluck('codigo')->all() ?? [];
    }

    public function temPermissao(string $codigo): bool
    {
        return in_array($codigo, $this->codigosPermissao(), true);
    }

    /**
     * IDs de órgãos a que o usuário está restrito, ou null quando vê todos.
     *
     * @return list<int>|null
     */
    public function orgaosPermitidos(): ?array
    {
        if (! $this->perfil?->restrito_por_orgao) {
            return null;
        }

        return $this->orgaos()->pluck('orgaos.id')->map(fn ($id) => (int) $id)->all();
    }
}
