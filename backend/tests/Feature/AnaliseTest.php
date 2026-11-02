<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Inteligencia\AnaliseTotalService;
use App\Services\Inteligencia\PrecosService;
use App\Support\Normalizador;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CenarioCompras;
use Tests\TestCase;

/** Etapa 3B: itens adjudicados, catálogo, compras por classe, análise do total e análise de preços. */
class AnaliseTest extends TestCase
{
    use CenarioCompras, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function usuario(string $perfil): User
    {
        return User::create(['name' => $perfil, 'email' => strtolower($perfil).'@teste.local', 'password' => 'segredo123', 'ativo' => true,
            'perfil_id' => Perfil::where('codigo', $perfil)->value('id')]);
    }

    public function test_normalizacao_e_semelhanca(): void
    {
        $this->assertSame('ARROZ TIPO 1 PACOTE 5KG', Normalizador::descricao('ARROZ, TIPO 1, PACOTE 5 KG'));
        $this->assertSame('ARROZ TIPO 1 PACOTE 5KG', Normalizador::descricao('Arroz tipo 1 pct 5kg'));
        $this->assertSame(1.0, Normalizador::semelhanca('LUVA LATEX TAMANHO M', 'LUVA LATEX TAMANHO M'));
        // Tamanhos diferentes não são o mesmo item.
        $this->assertLessThan(1.0, Normalizador::semelhanca('LUVA DE PROCEDIMENTO LATEX TAMANHO M', 'LUVA DE PROCEDIMENTO LATEX TAMANHO G'));
    }

    public function test_itens_adjudicados_so_com_resultado_economia_e_cpf_mascarado(): void
    {
        $this->processo('2025-03-01', 'PREGAO', $this->ids['uA'], [
            ['est' => 1000, 'adj' => 800, 'con' => 800],
            ['est' => 500, 'adj' => null, 'con' => null, 'situacao' => 'EM_ANDAMENTO'],
            ['est' => 200, 'con' => 200, 'fornecedor' => $this->ids['fPF']],
        ]);

        $this->actingAs($this->usuario('CONSULTA_GERENCIAL'), 'sanctum');
        $r = $this->getJson('/api/inteligencia/itens-adjudicados?ordem=valor_total_adjudicado&direcao=desc')->assertOk()->json();
        $this->assertSame(2, $r['total']);
        $this->assertEquals(20.0, $r['dados'][0]['percentual_economia']);
        $pf = collect($r['dados'])->firstWhere('fornecedor', 'Agricultor');
        $this->assertStringNotContainsString('12345678901', (string) $pf['fornecedor_documento']);

        // Situação local estreita entre as situações com resultado.
        $this->getJson('/api/inteligencia/itens-adjudicados?situacao[]=HOMOLOGADO')->assertOk()->assertJsonPath('total', 0);
        // Consulta pública não abre registros.
        $this->actingAs($this->usuario('CONSULTA_PUBLICA'), 'sanctum');
        $this->getJson('/api/inteligencia/itens-adjudicados')->assertForbidden();
    }

    public function test_catalogo_duplicidade_historico_e_equivalencia_com_validacao(): void
    {
        $agora = now();
        $base = ['classe_id' => $this->ids['classe'], 'created_at' => $agora, 'updated_at' => $agora];
        $a = DB::table('itens_catalogo')->insertGetId($base + ['codigo' => '2001', 'descricao' => 'Arroz tipo 1 pct 5kg', 'descricao_normalizada' => Normalizador::descricao('Arroz tipo 1 pct 5kg')]);
        $b = DB::table('itens_catalogo')->insertGetId($base + ['codigo' => '2002', 'descricao' => 'ARROZ, TIPO 1, PACOTE 5 KG', 'descricao_normalizada' => Normalizador::descricao('ARROZ, TIPO 1, PACOTE 5 KG'), 'especificacao' => 'Agulhinha']);

        $this->actingAs($this->usuario('CONSULTA_PUBLICA'), 'sanctum');
        $this->getJson('/api/catalogo?apenas_duplicados=1')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/catalogo/resumo')->assertOk()->assertJsonPath('grupos_duplicidade', 1);
        $this->postJson('/api/catalogo/equivalencias', ['item_id' => $a, 'equivalente_id' => $b])->assertForbidden();

        $analista = $this->usuario('ANALISTA');
        $this->actingAs($analista, 'sanctum');
        // Edição grava o antes e o depois.
        $this->putJson("/api/catalogo/$a", ['codigo' => '2001', 'descricao' => 'Arroz tipo 1 pacote 5 kg', 'tipo' => 'M', 'classe_id' => $this->ids['classe'], 'status' => 'ATIVO'])
            ->assertOk()->assertJsonPath('historico.0.alteracoes.0.campo', 'descricao');

        $id = $this->postJson('/api/catalogo/equivalencias', ['item_id' => $a, 'equivalente_id' => $b])->assertCreated()->json('id');
        $this->assertTrue((bool) DB::table('itens_equivalencias')->where('id', $id)->value('especificacoes_diferentes'));
        // Especificações diferentes: validar exige confirmação explícita.
        $this->postJson("/api/catalogo/equivalencias/$id/decisao", ['decisao' => 'VALIDAR', 'justificativa' => 'Mesmo produto conferido.'])->assertUnprocessable();
        $this->postJson("/api/catalogo/equivalencias/$id/decisao", ['decisao' => 'VALIDAR', 'justificativa' => 'Mesmo produto conferido.', 'confirmar_especificacao' => true])->assertOk();
        $this->assertSame('VALIDADA', DB::table('itens_equivalencias')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('logs_auditoria')->where('acao', 'equivalencia.validar')->count());
    }

    public function test_decomposicao_e_concentracao(): void
    {
        $this->processo('2024-05-01', 'PREGAO', $this->ids['uA'], [['est' => 600, 'con' => 600]]);
        $this->processo('2025-05-01', 'PREGAO', $this->ids['uA'], [['est' => 300, 'con' => 300, 'fornecedor' => $this->ids['fGR']]]);
        $this->processo('2025-06-01', 'PREGAO', $this->ids['uB'], [['est' => 100, 'con' => 100]]);
        $s = app(AnaliseTotalService::class);

        $anos = collect($s->decomposicao(new FiltrosAplicados, [], 'ano')['nos'])->keyBy('nome');
        $this->assertSame(400.0, $anos['2025']['contratado']);
        $this->assertSame(40.0, $anos['2025']['percentual']);

        $uc = $s->decomposicao(new FiltrosAplicados, [['dimensao' => 'ano', 'valor' => '2025']], 'unidade');
        $this->assertSame(400.0, $uc['total']);
        $this->assertSame('Unidade A', $uc['nos'][0]['nome']);
        $this->assertSame(300.0, $uc['nos'][0]['contratado']);

        // ME 700 (70%) e Grande 300 (30%): HHI = 70² + 30² = 5800.
        $c = $s->concentracao(new FiltrosAplicados);
        $this->assertSame(5800, $c['geral']['hhi']);
        $this->assertSame(70.0, $c['geral']['maior']);
    }

    public function test_estatisticas_e_alertas_de_preco(): void
    {
        $e = PrecosService::estatisticas(collect([10.0, 12.0, 14.0, 30.0]));
        $this->assertSame(13.0, $e['mediana']);
        $this->assertSame(16.5, $e['media']);
        $this->assertSame(200.0, $e['variacao']);

        $un = DB::table('unidades_medida')->insertGetId(['sigla' => 'KGX', 'nome' => 'Quilo', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['2026-01-10', 10], ['2026-02-10', 12], ['2026-03-10', 14], ['2026-04-10', 30]] as [$data, $preco]) {
            $p = $this->processo($data, 'PREGAO', $this->ids['uA'], [['est' => $preco * 10, 'con' => $preco * 10]]);
            DB::table('processos_itens')->where('processo_id', $p)->update(['unidade_medida_id' => $un, 'quantidade_adjudicada' => 10, 'valor_unitario_adjudicado' => $preco]);
        }
        $f = new FiltrosAplicados(hoje: CarbonImmutable::parse('2026-06-30'));
        $r = app(PrecosService::class)->item($f, $this->ids['item'], null, []);

        $this->assertSame($un, $r['unidade_id']);
        $this->assertSame(4, $r['estatisticas']['n']);
        $tipos = array_column($r['alertas'], 'tipo');
        $this->assertContains('ACIMA_MEDIANA', $tipos);  // 30 está 130% acima da mediana 13
        $this->assertContains('DISPERSAO_ALTA', $tipos);
        foreach ($r['alertas'] as $a) {
            $this->assertStringContainsString('análise humana', $a['aviso']);
            $this->assertStringNotContainsStringIgnoringCase('há sobrepreço', $a['mensagem']);
        }
        $this->assertFalse($r['estadual_nacional']['disponivel']);
        // Faixa de quantidade fora do intervalo: nenhuma referência, alerta de referências insuficientes.
        $r2 = app(PrecosService::class)->item($f, $this->ids['item'], $un, ['quantidade_min' => 100]);
        $this->assertSame(0, $r2['estatisticas']['n']);
        $this->assertSame('REFERENCIAS_INSUFICIENTES', $r2['alertas'][0]['tipo']);
    }
}
