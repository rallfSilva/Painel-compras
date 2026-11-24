<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CenarioCompras;
use Tests\TestCase;

/** API de entrada para outros sistemas (NexPlanGov): token, lotes, idempotência e isolamento. */
class IntegracaoEntradaTest extends TestCase
{
    use CenarioCompras, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        Storage::fake('local');
    }

    private static function cnpj(string $base): string
    {
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $s = 0;
            foreach ($pesos as $i => $p) {
                $s += (int) $base[$i] * $p;
            }
            $base .= $s % 11 < 2 ? 0 : 11 - $s % 11;
        }

        return $base;
    }

    private function token(string $sistema = 'NEXPLANGOV'): string
    {
        Artisan::call('sic:token-integracao', ['sistema' => $sistema]);
        preg_match('/^\d+\|\S+$/m', Artisan::output(), $m);
        $this->assertNotEmpty($m, 'o comando deve imprimir o token');

        return $m[0];
    }

    private function api(string $token): array
    {
        $this->app['auth']->forgetGuards(); // nos testes o guarda guarda o usuário entre requisições; cada token deve ser lido de novo

        return ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }

    public function test_lote_e_validado_gravado_com_a_origem_do_sistema_e_consultado(): void
    {
        $h = $this->api($this->token());
        $tipos = $this->getJson('/api/integracao/v1/tipos', $h)->assertOk()->json();
        $this->assertSame(['CLASSIFICACOES', 'UNIDADES', 'FORNECEDORES'], array_slice(array_column($tipos['tipos'], 'codigo'), 0, 3));
        $this->assertSame(5000, $tipos['limite_registros_por_lote']);

        $c1 = self::cnpj('333333330001');
        $r = $this->postJson('/api/integracao/v1/lotes', ['tipo' => 'FORNECEDORES', 'referencia' => 'Carga inicial', 'registros' => [
            ['documento' => $c1, 'razao_social' => 'Empresa Integrada', 'porte' => 'ME', 'municipio' => 'Boa Vista'],
            ['documento' => '12345678000100', 'razao_social' => 'CNPJ inválido'],
        ]], $h)->assertStatus(202)->json();

        // A fila é síncrona nos testes: o lote já foi validado e gravado.
        $l = $this->getJson("/api/integracao/v1/lotes/{$r['id']}", $h)->assertOk()->json();
        $this->assertSame(['CONCLUIDO', true, 2, 1, 1, 'NEXPLANGOV'], [$l['situacao'], $l['concluido'], $l['total_registros'], $l['importados'], $l['rejeitados'], $l['origem']]);
        $f = DB::table('fornecedores')->where('documento', $c1)->first();
        $this->assertSame(['Empresa Integrada', 'NEXPLANGOV', $r['id']], [$f->razao_social, $f->origem, (int) $f->importacao_id]);
        $erros = $this->getJson("/api/integracao/v1/lotes/{$r['id']}/erros", $h)->assertOk()->json('dados');
        $this->assertSame([3], array_column($erros, 'linha'));
        $this->assertStringContainsString('dígito verificador', $erros[0]['mensagem']);
        // Aparece no histórico da tela de importação, com a origem.
        $this->assertSame('NEXPLANGOV', DB::table('importacoes')->where('id', $r['id'])->value('origem'));
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'integracao.receber')->where('recurso_id', $r['id'])->exists());
    }

    public function test_dependencias_em_ordem_e_reenvio_atualiza_sem_duplicar(): void
    {
        $h = $this->api($this->token());
        DB::table('unidades_compradoras')->where('id', $this->ids['uA'])->update(['codigo' => 'UA01']);
        $forn = self::cnpj('555555550001');
        $processo = ['numero_publicacao' => '900/2026', 'unidade' => 'UA01', 'objeto' => 'Papel A4', 'data_abertura' => '2026-03-10', 'sistematica' => 'PREGAO',
            'forma' => 'PREGAO_ELETRONICO', 'registro_precos' => true, 'numero_item' => 1, 'descricao_item' => 'Papel A4', 'quantidade_estimada' => 100, 'valor_unitario_estimado' => 30];
        $this->postJson('/api/integracao/v1/lotes', ['tipo' => 'PROCESSOS', 'registros' => [$processo]], $h)->assertStatus(202);
        $r = $this->postJson('/api/integracao/v1/lotes', ['tipo' => 'RESULTADOS', 'registros' => [
            ['numero_publicacao' => '900/2026', 'numero_item' => 1, 'fornecedor_documento' => $forn, 'fornecedor_nome' => 'Papelaria RR', 'vencedor' => true,
                'quantidade_adjudicada' => 100, 'valor_unitario_adjudicado' => 25.5],
        ]], $h)->assertStatus(202)->json();
        $this->assertSame(1, $this->getJson("/api/integracao/v1/lotes/{$r['id']}", $h)->json('importados'));

        // Reenvio do processo com valor novo: atualiza o mesmo registro.
        $this->postJson('/api/integracao/v1/lotes', ['tipo' => 'PROCESSOS', 'registros' => [['valor_unitario_estimado' => 32] + $processo]], $h)->assertStatus(202);
        $this->assertSame(1, DB::table('processos')->where('numero_publicacao', '900/2026')->count());
        $p = DB::table('processos')->where('numero_publicacao', '900/2026')->first();
        $this->assertEquals(3200, $p->valor_estimado);
        $this->assertEquals(2550, $p->valor_adjudicado);
    }

    public function test_idempotencia_e_campos_desconhecidos(): void
    {
        $h = $this->api($this->token()) + ['Idempotency-Key' => 'fornecedores-lote-0001'];
        $corpo = ['tipo' => 'FORNECEDORES', 'registros' => [['documento' => self::cnpj('444444440001'), 'razao_social' => 'Empresa']]];
        $a = $this->postJson('/api/integracao/v1/lotes', $corpo, $h)->assertStatus(202)->json('id');
        $b = $this->postJson('/api/integracao/v1/lotes', $corpo, $h)->assertOk()->json('id');
        $this->assertSame($a, $b);
        $this->assertSame(1, DB::table('importacoes')->count());

        $this->postJson('/api/integracao/v1/lotes', ['tipo' => 'FORNECEDORES', 'registros' => [['cnpj_errado' => 'x']]], $this->api($this->token()))
            ->assertUnprocessable()->assertJsonFragment(['registros' => ['Campos desconhecidos para o tipo FORNECEDORES: cnpj_errado. Consulte GET /api/integracao/v1/tipos.']]);
        $this->postJson('/api/integracao/v1/lotes', ['tipo' => 'INEXISTENTE', 'registros' => [[]]], $this->api($this->token()))->assertUnprocessable();
    }

    public function test_isolamento_entre_token_de_sistema_e_usuarios(): void
    {
        $tokenNex = $this->token();
        $id = $this->postJson('/api/integracao/v1/lotes', ['tipo' => 'FORNECEDORES', 'registros' => [['documento' => self::cnpj('666666660001'), 'razao_social' => 'X']]],
            $this->api($tokenNex))->json('id');

        // Outro sistema não vê o lote do NexPlanGov.
        $this->getJson("/api/integracao/v1/lotes/$id", $this->api($this->token('FINANCEIRO')))->assertNotFound();
        // O token de sistema não abre o painel.
        $this->getJson('/api/dashboard/indicadores', $this->api($tokenNex))->assertForbidden();
        $this->getJson('/api/importacoes', $this->api($tokenNex))->assertForbidden();
        // O token de login de um administrador não serve na API de entrada.
        $admin = User::create(['name' => 'Admin', 'email' => 'adm@teste.local', 'password' => 'segredo123', 'ativo' => true, 'perfil_id' => Perfil::where('codigo', 'ADMIN')->value('id')]);
        $this->getJson('/api/integracao/v1/tipos', $this->api($admin->createToken('spa')->plainTextToken))->assertForbidden();
        // O usuário técnico do sistema não entra pela interface.
        $sistema = User::where('email', 'nexplangov@integracao.sic-selc.local')->first();
        $this->assertSame(Perfil::SISTEMA, $sistema->perfil->codigo);
        $sistema->forceFill(['password' => 'segredo123'])->save();
        $this->postJson('/api/auth/login', ['email' => $sistema->email, 'password' => 'segredo123'])->assertUnprocessable();
        // Gerar de novo revoga o token anterior; --revogar remove todos.
        $novo = $this->token();
        $this->getJson('/api/integracao/v1/tipos', $this->api($tokenNex))->assertUnauthorized();
        Artisan::call('sic:token-integracao', ['sistema' => 'NEXPLANGOV', '--revogar' => true]);
        $this->getJson('/api/integracao/v1/tipos', $this->api($novo))->assertUnauthorized();
    }
}
