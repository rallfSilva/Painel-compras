<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CenarioCompras;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use CenarioCompras, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->processo('2025-05-05', 'CHAMADA_PNAE', $this->ids['uA'], [['est' => 300, 'fornecedor' => $this->ids['fPF']]]);
    }

    private function usuario(string $perfil): User
    {
        return User::create(['name' => $perfil, 'email' => strtolower($perfil).'@teste.local', 'password' => 'segredo123', 'ativo' => true, 'perfil_id' => Perfil::where('codigo', $perfil)->value('id')]);
    }

    public function test_login_e_acesso_autenticado(): void
    {
        $this->usuario('GESTOR_SELC');
        $resp = $this->postJson('/api/auth/login', ['email' => 'gestor_selc@teste.local', 'password' => 'segredo123'])->assertOk();
        $token = $resp->json('token');

        $this->getJson('/api/dashboard/indicadores', ['Authorization' => "Bearer $token"])
            ->assertOk()
            ->assertJsonCount(22, 'indicadores')
            ->assertJsonPath('indicadores.12.codigo', 'total_empenhado')
            ->assertJsonPath('indicadores.12.informado', false);
    }

    public function test_login_invalido(): void
    {
        $this->usuario('GESTOR_SELC');
        $this->postJson('/api/auth/login', ['email' => 'gestor_selc@teste.local', 'password' => 'errada'])->assertStatus(422);
    }

    public function test_sem_token_retorna_401(): void
    {
        $this->getJson('/api/dashboard/indicadores')->assertUnauthorized();
    }

    public function test_consulta_publica_nao_abre_registros(): void
    {
        $this->actingAs($this->usuario('CONSULTA_PUBLICA'), 'sanctum');

        $this->getJson('/api/dashboard/indicadores')->assertOk();
        $this->getJson('/api/dashboard/indicadores/processos/registros')->assertForbidden();
    }

    public function test_cpf_de_pessoa_fisica_e_mascarado_sem_permissao(): void
    {
        $this->actingAs($this->usuario('CONSULTA_GERENCIAL'), 'sanctum');
        $doc = $this->getJson('/api/dashboard/indicadores/fornecedores/registros')->assertOk()->json('dados.0.fornecedor_documento');
        $this->assertSame('***.456.***-**', $doc);

        $this->actingAs($this->usuario('ADMIN'), 'sanctum');
        $doc = $this->getJson('/api/dashboard/indicadores/fornecedores/registros')->json('dados.0.fornecedor_documento');
        $this->assertSame('12345678901', $doc);
    }

    public function test_registros_fecham_com_o_indicador(): void
    {
        $this->actingAs($this->usuario('ADMIN'), 'sanctum');
        $this->processo('2025-03-10', 'PREGAO', $this->ids['uA'], [['est' => 1000, 'adj' => 800], ['est' => 50, 'adj' => 40]]);

        $total = collect($this->getJson('/api/dashboard/indicadores?f[tempo][]=a:2025')->json('indicadores'))->firstWhere('codigo', 'itens_comprados')['valor'];
        $lista = $this->getJson('/api/dashboard/indicadores/itens_comprados/registros?f[tempo][]=a:2025')->json('total');

        $this->assertSame($total, $lista);
    }

    public function test_opcoes_de_filtro(): void
    {
        $this->actingAs($this->usuario('ADMIN'), 'sanctum');
        $opcoes = $this->getJson('/api/filtros/opcoes')->assertOk()->json('opcoes');

        $this->assertSame('a:2025', $opcoes['tempo'][0]['key']);
        $this->assertCount(2, $opcoes['tempo'][0]['children']); // semestres
        $this->assertNotEmpty($opcoes['sistematica']);
    }
}
