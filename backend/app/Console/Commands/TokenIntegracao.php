<?php

namespace App\Console\Commands;

use App\Http\Middleware\ExigeTokenIntegracao;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Cria (ou revoga) o token de um sistema que envia dados pela API de entrada.
 * O sistema é representado por um usuário técnico do perfil INTEGRACAO_SISTEMA, que não entra pela
 * interface e só pode enviar lotes. O token aparece uma única vez: guarde-o no .env do outro sistema.
 */
class TokenIntegracao extends Command
{
    protected $signature = 'sic:token-integracao {sistema : Código do sistema, ex.: NEXPLANGOV} {--revogar : Revoga os tokens do sistema sem criar um novo}';

    protected $description = 'Gera o token de acesso de um sistema à API de entrada (/api/integracao/v1)';

    public function handle(): int
    {
        $codigo = Str::upper((string) $this->argument('sistema'));
        if (! preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $codigo)) {
            $this->error('Código inválido: use de 2 a 30 letras maiúsculas, números ou sublinhado, começando por letra (ex.: NEXPLANGOV).');

            return self::FAILURE;
        }

        $permissao = Permissao::firstOrCreate(['codigo' => 'integracao.enviar'], ['descricao' => 'Enviar dados pela API de entrada (só para sistemas integrados)']);
        $perfil = Perfil::firstOrCreate(['codigo' => Perfil::SISTEMA], ['nome' => 'Sistema integrado (API)', 'restrito_por_orgao' => false]);
        $perfil->permissoes()->sync([$permissao->id]);

        $email = Str::lower($codigo).'@integracao.sic-selc.local';
        $usuario = User::where('email', $email)->first();
        if ($this->option('revogar')) {
            $n = $usuario?->tokens()->delete() ?? 0;
            $this->info("$n token(s) do sistema $codigo revogado(s).");

            return self::SUCCESS;
        }

        $usuario ??= User::create(['name' => "Sistema $codigo", 'email' => $email, 'password' => Str::password(48), 'ativo' => true, 'perfil_id' => $perfil->id]);
        $usuario->forceFill(['perfil_id' => $perfil->id, 'ativo' => true])->save();
        $usuario->tokens()->delete(); // um token ativo por sistema: gerar de novo substitui o anterior
        $token = $usuario->createToken($codigo, [ExigeTokenIntegracao::HABILIDADE])->plainTextToken;

        $this->info("Token do sistema $codigo (aparece só agora; o anterior, se havia, foi revogado):");
        $this->line($token);
        $this->line('Use no cabeçalho: Authorization: Bearer <token>   ·   Base: '.rtrim((string) config('app.url'), '/').'/api/integracao/v1');

        return self::SUCCESS;
    }
}
