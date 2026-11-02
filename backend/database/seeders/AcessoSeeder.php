<?php

namespace Database\Seeders;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Perfis sugeridos (seção 29), permissões e usuário administrador inicial. */
class AcessoSeeder extends Seeder
{
    public function run(): void
    {
        $permissoes = [
            'dashboard.ver' => 'Ver painéis e indicadores',
            'registros.ver' => 'Abrir registros que compõem os indicadores',
            'relatorios.exportar' => 'Exportar e imprimir relatórios',
            'importacao.executar' => 'Executar importações de dados',
            'cadastros.gerenciar' => 'Gerenciar cadastros (órgãos, unidades, fornecedores, catálogo)',
            'classificacoes.gerenciar' => 'Gerenciar classificações e equivalências',
            'integracoes.gerenciar' => 'Gerenciar integrações',
            'usuarios.gerenciar' => 'Gerenciar usuários e permissões',
            'configuracoes.gerenciar' => 'Gerenciar parâmetros e indicadores',
            'logs.ver' => 'Consultar logs de auditoria',
            'ia.consultar' => 'Usar o assistente de IA',
            'dados_pessoais.ver' => 'Ver CPF completo de fornecedor pessoa física',
        ];
        foreach ($permissoes as $codigo => $descricao) {
            Permissao::updateOrCreate(['codigo' => $codigo], ['descricao' => $descricao]);
        }

        $todas = array_keys($permissoes);
        $leitura = ['dashboard.ver', 'registros.ver', 'relatorios.exportar'];
        $perfis = [
            ['ADMIN', 'Administrador Geral', false, $todas],
            ['GESTOR_SELC', 'Gestor SELC', false, array_diff($todas, ['usuarios.gerenciar', 'configuracoes.gerenciar'])],
            ['ANALISTA', 'Analista de Dados', false, [...$leitura, 'cadastros.gerenciar', 'classificacoes.gerenciar', 'ia.consultar', 'logs.ver']],
            ['OPERADOR_IMPORTACAO', 'Operador de Importação', false, [...$leitura, 'importacao.executar']],
            ['GESTOR_ORGAO', 'Gestor de Órgão', true, [...$leitura, 'ia.consultar']],
            ['CONSULTA_GERENCIAL', 'Consulta Gerencial', false, $leitura],
            ['CONSULTA_PUBLICA', 'Consulta Pública', false, ['dashboard.ver']],
        ];
        foreach ($perfis as [$codigo, $nome, $restrito, $perms]) {
            $perfil = Perfil::updateOrCreate(['codigo' => $codigo], ['nome' => $nome, 'restrito_por_orgao' => $restrito]);
            $perfil->permissoes()->sync(Permissao::whereIn('codigo', $perms)->pluck('id'));
        }

        User::updateOrCreate(['email' => env('SEED_ADMIN_EMAIL', 'admin@sic-selc.local')], [
            'name' => 'Administrador',
            'password' => env('SEED_ADMIN_PASSWORD', 'Trocar@123'),
            'perfil_id' => Perfil::where('codigo', 'ADMIN')->value('id'),
            'ativo' => true,
        ]);
    }
}
