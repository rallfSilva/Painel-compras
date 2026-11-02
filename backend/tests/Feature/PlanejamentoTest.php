<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CenarioCompras;
use Tests\TestCase;

/** Etapa 4: PCA, contratos, relatórios gerenciais e administração. */
class PlanejamentoTest extends TestCase
{
    use CenarioCompras, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function usuario(string $perfil, array $orgaos = []): User
    {
        $u = User::create(['name' => $perfil, 'email' => strtolower($perfil).'@teste.local', 'password' => 'segredo123', 'ativo' => true,
            'perfil_id' => Perfil::where('codigo', $perfil)->value('id')]);
        $u->orgaos()->sync($orgaos);

        return $u;
    }

    private function demanda(int $pca, float $valor, string $situacao, ?string $data = null, ?int $processo = null): int
    {
        $id = DB::table('pca_demandas')->insertGetId(['pca_id' => $pca, 'numero_demanda' => 'D'.random_int(1, 99999), 'objeto' => 'Objeto',
            'categoria' => 'MATERIAL', 'classe_id' => $this->ids['classe'], 'valor_estimado' => $valor, 'data_pretendida' => $data ?? now()->addMonth()->toDateString(),
            'situacao_execucao' => $situacao, 'created_at' => now(), 'updated_at' => now()]);
        if ($processo) {
            DB::table('pca_execucao')->insert(['demanda_id' => $id, 'processo_id' => $processo, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    public function test_pca_planejado_contratado_atrasadas_e_fora_do_pca(): void
    {
        $ano = (int) now()->year;
        $pcaA = DB::table('pca')->insertGetId(['exercicio' => $ano, 'orgao_id' => $this->ids['orgA'], 'situacao' => 'APROVADO', 'created_at' => now(), 'updated_at' => now()]);
        $pcaB = DB::table('pca')->insertGetId(['exercicio' => $ano, 'orgao_id' => $this->ids['orgB'], 'situacao' => 'APROVADO', 'created_at' => now(), 'updated_at' => now()]);
        $p1 = $this->processo(now()->toDateString(), 'PREGAO', $this->ids['uA'], [['est' => 1000, 'adj' => 600, 'con' => 600]]);
        $this->processo(now()->toDateString(), 'PREGAO', $this->ids['uA'], [['est' => 400, 'adj' => 400, 'con' => 400]]); // fora do PCA
        $this->demanda($pcaA, 1000, 'CONCLUIDA', null, $p1);
        $this->demanda($pcaA, 500, 'NAO_INICIADA', now()->subDays(10)->toDateString()); // atrasada
        $this->demanda($pcaB, 300, 'EM_ANDAMENTO');

        $this->actingAs($this->usuario('GESTOR_SELC'), 'sanctum');
        $i = $this->getJson('/api/pca')->assertOk()->json('indicadores');
        $this->assertEquals(1800, $i['planejado']);
        $this->assertEquals(600, $i['contratado']);
        $this->assertEquals(33.33, $i['percentual_executado']);
        $this->assertSame(1, $i['atrasadas']);
        $fora = $this->getJson('/api/pca')->json('fora_do_pca');
        $this->assertSame(1, $fora['processos']);
        $this->assertEquals(400, $fora['contratado']);

        // Gestor de órgão vê só o PCA do próprio órgão.
        $this->actingAs($this->usuario('GESTOR_ORGAO', [$this->ids['orgB']]), 'sanctum');
        $this->getJson('/api/pca')->assertOk()->assertJsonPath('indicadores.planejado', 300);
        $this->getJson('/api/pca/demandas')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_contratos_sinalizam_acrescimo_e_vencimento(): void
    {
        $p = $this->processo(now()->toDateString(), 'PREGAO', $this->ids['uA'], [['est' => 1000, 'adj' => 1000, 'con' => 1000]]);
        $mk = fn (string $uid, float $inicial, int $dias) => DB::table('contratos')->insertGetId(['uid' => $uid, 'numero' => $uid, 'processo_id' => $p,
            'unidade_compradora_id' => $this->ids['uA'], 'fornecedor_id' => $this->ids['fME'], 'objeto' => 'Objeto', 'valor_inicial' => $inicial,
            'vigencia_inicio' => now()->subYear()->toDateString(), 'vigencia_fim' => now()->addDays($dias)->toDateString(), 'situacao' => 'VIGENTE',
            'created_at' => now(), 'updated_at' => now()]);
        $acima = $mk('C1', 1000, 20);
        $proximo = $mk('C2', 1000, 75);
        $mk('C3', 1000, 200);
        foreach ([[$acima, 300], [$proximo, 210]] as [$c, $v]) {
            DB::table('contratos_aditivos')->insert(['contrato_id' => $c, 'numero' => '1', 'tipo' => 'VALOR', 'valor' => $v, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($this->usuario('GESTOR_SELC'), 'sanctum');
        $d = $this->getJson('/api/contratos/painel')->assertOk()->json();
        $this->assertSame(3, $d['indicadores']['vigentes']);
        $this->assertSame(2, $d['indicadores']['sinalizados']);
        $this->assertSame([1, 1, 2], array_column($d['vencendo'], 'quantidade'));

        $l = collect($this->getJson('/api/contratos?sinalizados=1')->assertOk()->json('dados'))->keyBy('numero');
        $this->assertCount(2, $l);
        $this->assertSame('ACIMA', $l['C1']['sinal_acrescimo']);
        $this->assertSame('PROXIMO', $l['C2']['sinal_acrescimo']);
        $this->assertEquals(1300, $l['C1']['valor_atualizado']);
        $this->getJson('/api/contratos?vencendo_em=30')->assertJsonPath('total', 1);
        $this->getJson("/api/contratos/$acima")->assertOk()->assertJsonPath('contrato.percentual_acrescimo', 30)->assertJsonPath('financeiro', null);
    }

    public function test_relatorio_gerado_com_filtros_e_registrado_no_log(): void
    {
        $this->processo(now()->toDateString(), 'PREGAO', $this->ids['uA'], [['est' => 1000, 'adj' => 800, 'con' => 800]]);
        $this->processo(now()->toDateString(), 'PREGAO', $this->ids['uB'], [['est' => 500, 'adj' => 500, 'con' => 500]]);
        $u = $this->usuario('GESTOR_SELC');
        $this->actingAs($u, 'sanctum');

        $this->assertCount(18, $this->getJson('/api/relatorios')->assertOk()->json('relatorios'));
        $r = $this->getJson('/api/relatorios/orgao')->assertOk()->json();
        $this->assertSame(2, $r['total_linhas']);
        $r = $this->getJson('/api/relatorios/orgao?f[unidade][]=o:'.$this->ids['orgA'])->assertOk()->json();
        $this->assertSame(1, $r['total_linhas']);
        $this->assertTrue(DB::table('logs_auditoria')->where('user_id', $u->id)->where('acao', 'relatorio.gerar')->exists());
        $this->getJson('/api/relatorios/inexistente')->assertNotFound();

        // Consulta pública não exporta relatórios.
        $this->actingAs($this->usuario('CONSULTA_PUBLICA'), 'sanctum');
        $this->getJson('/api/relatorios/orgao')->assertForbidden();
    }

    public function test_administracao_permissoes_protecoes_e_auditoria(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->actingAs($admin, 'sanctum');

        // Cadastro genérico com histórico de alterações.
        $id = $this->postJson('/api/admin/cadastros/orgaos', ['nome' => 'Órgão C', 'sigla' => 'C', 'tipo' => 'AUTARQUIA', 'ativo' => true])->assertCreated()->json('id');
        $this->putJson("/api/admin/cadastros/orgaos/$id", ['nome' => 'Órgão C Alterado', 'sigla' => 'C', 'tipo' => 'AUTARQUIA', 'ativo' => true])->assertOk();
        $this->assertSame('Órgão C Alterado', DB::table('orgaos')->where('id', $id)->value('nome'));
        $this->assertSame(2, DB::table('historico_alteracoes')->where('tabela', 'orgaos')->where('registro_id', $id)->count());
        $this->postJson('/api/admin/cadastros/orgaos', ['sigla' => 'X'])->assertUnprocessable();

        // Usuário restrito por órgão exige órgão; o admin não se desativa.
        $gestor = Perfil::where('codigo', 'GESTOR_ORGAO')->value('id');
        $this->postJson('/api/admin/usuarios', ['nome' => 'Novo', 'email' => 'novo@teste.local', 'perfil_id' => $gestor, 'senha' => 'senha1234'])->assertUnprocessable();
        $this->postJson('/api/admin/usuarios', ['nome' => 'Novo', 'email' => 'novo@teste.local', 'perfil_id' => $gestor, 'senha' => 'senha1234', 'orgaos' => [$this->ids['orgA']]])->assertCreated();
        $this->putJson("/api/admin/usuarios/$admin->id", ['nome' => 'ADMIN', 'email' => $admin->email, 'perfil_id' => $admin->perfil_id, 'ativo' => false])->assertUnprocessable();

        // Perfis: ADMIN não muda; os demais mudam e ficam no log.
        $this->putJson('/api/admin/perfis/'.$admin->perfil_id, ['permissoes' => ['dashboard.ver']])->assertUnprocessable();
        $analista = Perfil::where('codigo', 'ANALISTA')->value('id');
        $this->putJson("/api/admin/perfis/$analista", ['permissoes' => ['dashboard.ver', 'registros.ver']])->assertOk();
        $this->assertSame(2, Perfil::find($analista)->permissoes()->count());
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'perfis.permissoes')->exists());
        $this->getJson('/api/admin/logs')->assertOk()->assertJsonStructure(['dados', 'total']);

        // Sem permissão administrativa.
        $this->actingAs($this->usuario('CONSULTA_GERENCIAL'), 'sanctum');
        $this->getJson('/api/admin/cadastros/orgaos')->assertForbidden();
        $this->getJson('/api/admin/usuarios')->assertForbidden();
        $this->getJson('/api/admin')->assertOk()->assertJsonPath('cadastros', []);
    }
}
