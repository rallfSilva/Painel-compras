<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use App\Services\Ia\InterpretadorRegras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CenarioCompras;
use Tests\TestCase;

/** Etapa 6: assistente em linguagem natural, alertas e recomendações (seção 32). */
class AssistenteTest extends TestCase
{
    use CenarioCompras, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        DB::table('grupos_materiais')->where('id', $this->ids['grupo'])->update(['nome' => 'EQUIPAMENTOS DE INFORMÁTICA']);
        config(['ia.provider' => 'none']);
    }

    private function usuario(string $perfil, array $orgaos = []): User
    {
        $u = User::create(['name' => $perfil, 'email' => strtolower($perfil).'@teste.local', 'password' => 'segredo123', 'ativo' => true,
            'perfil_id' => Perfil::where('codigo', $perfil)->value('id')]);
        $u->orgaos()->sync($orgaos);

        return $u;
    }

    public function test_interpretador_por_regras_reconhece_as_perguntas_da_especificacao(): void
    {
        $esperado = [
            'Quanto o Governo de Roraima comprou de material de informática em 2025?' => 'total_comprado',
            'Qual secretaria realizou mais compras?' => 'ranking_orgaos',
            'Quais foram os 20 itens mais comprados?' => 'ranking_itens',
            'Qual foi a economia média das licitações?' => 'economia_media',
            'Quais órgãos compraram os mesmos materiais?' => 'orgaos_mesmos_materiais',
            'Quais fornecedores mais venderam para o Governo?' => 'ranking_fornecedores',
            'Quais itens apresentaram maior variação de preço?' => 'variacao_precos',
            'Quais atas estão próximas do vencimento?' => 'atas_vencendo',
            'Quais atas já ultrapassaram 100% de utilização?' => 'atas_acima_100',
            'Quais compras podem ser planejadas conjuntamente?' => 'compras_conjuntas',
            'Quais órgãos possuem maior participação de MPE?' => 'participacao_mpe',
            'Quanto foi comprado da agricultura familiar?' => 'agricultura_familiar',
            'Quais municípios concentram maior volume de compras?' => 'municipios_volume',
            'Quais contratos vencem nos próximos 60 dias?' => 'contratos_vencendo',
        ];
        $r = new InterpretadorRegras;
        foreach ($esperado as $pergunta => $consulta) {
            $this->assertSame($consulta, $r->interpretar($pergunta)->consulta, $pergunta);
        }

        $p = $r->interpretar('Quanto o Governo de Roraima comprou de material de informática em 2025?')->parametros;
        $this->assertSame([2025], $p['anos']);
        $this->assertSame('material de informatica', $p['termo']);
        $this->assertSame(20, $r->interpretar('Quais foram os 20 itens mais comprados?')->parametros['limite']);
        $this->assertSame([2022, 2023, 2024], $r->interpretar('Quanto foi gasto entre 2022 e 2024?')->parametros['anos']);
        $this->assertSame(60, $r->interpretar('Quais contratos vencem nos próximos 60 dias?')->parametros['dias']);
        $this->assertNull($r->interpretar('Qual a previsão do tempo amanhã?')->consulta);
    }

    public function test_resposta_usa_os_dados_do_sistema_respeita_o_orgao_e_registra_auditoria(): void
    {
        $this->processo('2025-03-10', 'PREGAO', $this->ids['uA'], [['est' => 1000, 'con' => 800]]);
        $this->processo('2025-05-10', 'PREGAO', $this->ids['uB'], [['est' => 5000, 'con' => 4000]]);
        $this->processo('2024-05-10', 'PREGAO', $this->ids['uA'], [['est' => 300, 'con' => 300]]);

        $pergunta = 'Quanto o Governo de Roraima comprou de material de informática em 2025?';
        $this->actingAs($this->usuario('GESTOR_SELC'), 'sanctum');
        $r = $this->postJson('/api/ia/perguntar', ['pergunta' => $pergunta])->assertOk()->json();
        $this->assertSame('total_comprado', $r['consulta']['codigo']);
        $this->assertFalse($r['sem_dados']);
        $this->assertSame('Ano de 2025', $r['periodo']);
        $this->assertStringContainsString('R$ 4.800,00', $r['resposta']);
        $this->assertEquals(4800, collect($r['indicadores'])->firstWhere('codigo', 'total_contratado')['valor']);
        $this->assertSame('estimativa', collect($r['indicadores'])->firstWhere('codigo', 'total_estimado')['natureza']);
        $this->assertSame('EQUIPAMENTOS DE INFORMÁTICA', collect($r['filtros'])->firstWhere('filtro', 'classe')['valor']);
        $this->assertSame('compras-totais', $r['registros']['modulo']);
        $this->assertContains('g:'.$this->ids['grupo'], array_column($r['registros']['filtros'], 'chave'));
        $this->assertNotEmpty($r['fonte']);
        $this->assertDatabaseHas('logs_auditoria', ['acao' => 'ia.consultar', 'recurso' => 'assistente']);

        // Gestor de órgão: só vê o próprio órgão, mesmo perguntando pelo total do Governo.
        $this->actingAs($this->usuario('GESTOR_ORGAO', [$this->ids['orgA']]), 'sanctum');
        $r = $this->postJson('/api/ia/perguntar', ['pergunta' => $pergunta])->assertOk()->json();
        $this->assertEquals(800, collect($r['indicadores'])->firstWhere('codigo', 'total_contratado')['valor']);
        $this->assertSame('acesso', collect($r['filtros'])->last()['filtro']);

        // Ranking por órgão, com filtro do painel somado à pergunta.
        $this->actingAs($this->usuario('ANALISTA'), 'sanctum');
        $r = $this->postJson('/api/ia/perguntar?f[tempo][]=a:2025', ['pergunta' => 'Qual secretaria realizou mais compras?'])->assertOk()->json();
        $this->assertSame('B', $r['tabela']['linhas'][0]['nome']);
        $this->assertSame('unidade', $r['tabela']['linhas'][0]['_abrir']['filtro']);
        $this->assertSame('painel', collect($r['filtros'])->firstWhere('filtro', 'tempo')['origem']);
    }

    public function test_sem_dados_nao_inventa_numeros(): void
    {
        $this->actingAs($this->usuario('GESTOR_SELC'), 'sanctum');

        $r = $this->postJson('/api/ia/perguntar', ['pergunta' => 'Quanto foi comprado de material de informática em 2019?'])->assertOk()->json();
        $this->assertTrue($r['sem_dados']);
        $this->assertSame([], $r['indicadores']);
        $this->assertStringStartsWith('Não há valor contratado', $r['resposta']);

        $r = $this->postJson('/api/ia/perguntar', ['pergunta' => 'Quanto comprou de xyzabc?'])->assertOk()->json();
        $this->assertTrue($r['sem_dados']);
        $this->assertStringContainsString('Não encontrei no catálogo', $r['resposta']);

        $r = $this->postJson('/api/ia/perguntar', ['pergunta' => 'Qual a previsão do tempo amanhã?'])->assertOk()->json();
        $this->assertNull($r['consulta']);
        $this->assertNotEmpty($r['sugestoes']);
        $this->assertStringContainsString('conclusão jurídica', implode(' ', $r['avisos']));
    }

    public function test_permissao_validacao_e_configuracao(): void
    {
        $this->actingAs($this->usuario('CONSULTA_GERENCIAL'), 'sanctum');
        $this->postJson('/api/ia/perguntar', ['pergunta' => 'Qual secretaria realizou mais compras?'])->assertForbidden();
        $this->getJson('/api/ia/alertas')->assertForbidden();

        $this->actingAs($this->usuario('ANALISTA'), 'sanctum');
        $this->postJson('/api/ia/perguntar', ['pergunta' => ''])->assertUnprocessable();
        $c = $this->getJson('/api/ia')->assertOk()->json();
        $this->assertSame('none', $c['provedor']);
        $this->assertCount(14, $c['consultas']);
    }

    public function test_modelo_de_linguagem_so_classifica_e_tem_alternativa_local(): void
    {
        $this->processo('2025-03-10', 'PREGAO', $this->ids['uA'], [['est' => 1000, 'con' => 800]]);
        config(['ia.provider' => 'ollama', 'ia.model' => 'modelo-teste', 'ia.base_url' => 'http://ollama.local']);
        $conteudo = '{"consulta": "ranking_orgaos", "parametros": {"limite": 3}}';
        Http::fake(['ollama.local/*' => function () use (&$conteudo) {
            return $conteudo === null ? Http::response('erro', 500) : Http::response(['message' => ['content' => $conteudo]]);
        }]);

        $this->actingAs($this->usuario('ANALISTA'), 'sanctum');
        $r = $this->postJson('/api/ia/perguntar', ['pergunta' => 'Quem gastou mais dinheiro?'])->assertOk()->json();
        $this->assertSame('ollama', $r['interpretador']);
        $this->assertSame('ranking_orgaos', $r['consulta']['codigo']);
        $this->assertEquals(800, $r['tabela']['linhas'][0]['contratado']);
        // O modelo recebe só a pergunta e o catálogo: nenhum dado do banco sai do sistema.
        Http::assertSent(fn (HttpRequest $req) => str_contains($req->body(), 'Quem gastou mais dinheiro?') && ! str_contains($req->body(), '800'));

        // Consulta fora do catálogo é descartada.
        $conteudo = '{"consulta": "DROP TABLE", "parametros": {}}';
        $this->assertNull($this->postJson('/api/ia/perguntar', ['pergunta' => 'Quem gastou mais dinheiro?'])->json('consulta'));

        // Modelo fora do ar: vale o interpretador por regras, com aviso.
        $conteudo = null;
        $r = $this->postJson('/api/ia/perguntar', ['pergunta' => 'Qual secretaria realizou mais compras?'])->assertOk()->json();
        $this->assertSame('regras', $r['interpretador']);
        $this->assertSame('ranking_orgaos', $r['consulta']['codigo']);
        $this->assertStringContainsString('regras locais', implode(' ', $r['avisos']));
    }

    public function test_alertas_e_recomendacoes(): void
    {
        $p = $this->processo(now()->subMonths(2)->toDateString(), 'PREGAO', $this->ids['uA'], [['est' => 1000, 'con' => 800]]);
        DB::table('contratos')->insert(['uid' => 'C-1', 'numero' => '1/2026', 'processo_id' => $p, 'unidade_compradora_id' => $this->ids['uA'], 'fornecedor_id' => $this->ids['fME'],
            'objeto' => 'Teste', 'valor_inicial' => 800, 'vigencia_inicio' => now()->subYear()->toDateString(), 'vigencia_fim' => now()->addDays(20)->toDateString(),
            'situacao' => 'VIGENTE', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->usuario('ANALISTA'), 'sanctum');
        $d = $this->getJson('/api/ia/alertas')->assertOk()->json();
        $a = collect($d['alertas'])->firstWhere('codigo', 'contratos_vencendo');
        $this->assertSame(1, $a['quantidade']);
        $this->assertSame('alta', $a['severidade']);
        $this->assertSame('contratos', $a['modulo']);
        $this->assertNotEmpty($a['recomendacao']);
        $this->assertStringContainsString('conclusão jurídica', $d['aviso']);
    }
}
