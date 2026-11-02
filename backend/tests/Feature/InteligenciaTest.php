<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Indicadores\OpcoesCalculo;
use App\Services\Inteligencia\AlimentosService;
use App\Services\Inteligencia\AquisicoesRpService;
use App\Services\Inteligencia\LicitacoesRpService;
use App\Services\Inteligencia\MpeService;
use App\Services\Inteligencia\SistematicaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CenarioCompras;
use Tests\TestCase;

/** Etapa 3A: MPE, Alimentos/PNAE, Registro de Preços e painéis por localidade, natureza e sistemática. */
class InteligenciaTest extends TestCase
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

    public function test_mpe_categorias_beneficios_e_localizacao(): void
    {
        $p = $this->processo('2025-03-01', 'PREGAO', $this->ids['uA'], [
            ['est' => 600, 'con' => 600],                                   // ME de RR
            ['est' => 300, 'con' => 300, 'fornecedor' => $this->ids['fGR']], // Grande de SP
            ['est' => 100, 'con' => 100, 'fornecedor' => $this->ids['fPF']], // Agricultor familiar
        ]);
        DB::table('processos_itens')->where('processo_id', $p)->where('numero_item', 1)->update(['beneficio_mpe' => 'EXCLUSIVO']);

        $r = app(MpeService::class)->painel(new FiltrosAplicados);
        $i = $r['indicadores'];

        $this->assertSame(600.0, $i['valor_mpe']);
        $this->assertSame(60.0, $i['percentual_mpe']);
        $this->assertSame(1, $i['fornecedores_mpe']);
        $this->assertSame(1, $i['itens_exclusivos']);
        $this->assertSame(600.0, $i['valor_exclusivos']);
        $this->assertSame(100.0, $i['valor_agricultura_familiar']); // PAF fica em categoria própria, fora das MPE
        $this->assertSame(100.0, $i['percentual_mpe_rr']);

        $cat = collect($r['por_categoria'])->keyBy('categoria');
        $this->assertSame(60.0, $cat['MPE']['percentual']);
        $this->assertSame(10.0, $cat['AF']['percentual']);
        $this->assertSame(30.0, $cat['DEMAIS']['percentual']);
        $this->assertSame(100.0, collect($r['localizacao'])->firstWhere('grupo', 'MESMO_MUNICIPIO')['percentual']);
    }

    public function test_alimentos_e_aproximacao_do_pnae(): void
    {
        DB::table('itens_catalogo')->where('id', $this->ids['item'])->update(['categoria_alimento' => 'FRUTAS', 'perecivel' => true]);
        $this->processo('2025-03-01', 'CHAMADA_PNAE', $this->ids['uA'], [['est' => 250, 'con' => 250, 'fornecedor' => $this->ids['fPF']]]);
        $this->processo('2025-04-01', 'PREGAO', $this->ids['uA'], [['est' => 750, 'con' => 750]]);

        $r = app(AlimentosService::class)->painel(new FiltrosAplicados);

        $this->assertSame(1000.0, $r['indicadores']['valor']);
        $this->assertSame(25.0, $r['indicadores']['percentual_pnae']);
        $this->assertSame(25.0, $r['pnae']['percentual_aproximado']);
        $this->assertSame(30.0, $r['pnae']['percentual_minimo']);
        $this->assertFalse($r['pnae']['atingido']);
        $this->assertSame('FRUTAS', $r['por_categoria'][0]['categoria']);
        $this->assertSame(1000.0, $r['perecibilidade'][0]['contratado']);
    }

    /** Ata com um item: 10 un. registradas a R$ 8 (estimado R$ 10), 12 un. usadas por um participante. */
    private function ataComUso(int $quantidadeUsada, string $vigenciaFim = '2026-12-31'): int
    {
        $p = $this->processo('2025-03-01', 'PREGAO', $this->ids['uA'], [['est' => 100, 'adj' => 80, 'con' => null]], ['registro_precos' => true]);
        $pi = DB::table('processos_itens')->where('processo_id', $p)->value('id');
        $agora = now();
        $ata = DB::table('atas_registro_precos')->insertGetId(['uid' => "ATA-$p", 'numero' => '001/2025', 'processo_id' => $p, 'orgao_gerenciador_id' => $this->ids['uA'],
            'objeto' => 'Ata', 'vigencia_inicio' => '2025-04-01', 'vigencia_fim' => $vigenciaFim, 'situacao' => 'VIGENTE', 'created_at' => $agora, 'updated_at' => $agora]);
        $ai = DB::table('atas_itens')->insertGetId(['ata_id' => $ata, 'processo_item_id' => $pi, 'item_catalogo_id' => $this->ids['item'], 'fornecedor_id' => $this->ids['fME'],
            'quantidade_registrada' => 10, 'valor_unitario_estimado' => 10, 'valor_unitario_registrado' => 8, 'created_at' => $agora, 'updated_at' => $agora]);
        foreach ([[$this->ids['uA'], 'GERENCIADOR', 4], [$this->ids['uB'], 'PARTICIPANTE', 6]] as [$u, $tipo, $qtd]) {
            DB::table('atas_participantes')->insert(['ata_id' => $ata, 'ata_item_id' => $ai, 'unidade_compradora_id' => $u, 'tipo' => $tipo,
                'quantidade_prevista' => $qtd, 'created_at' => $agora, 'updated_at' => $agora]);
        }
        DB::table('atas_utilizacoes')->insert(['ata_item_id' => $ai, 'unidade_compradora_id' => $this->ids['uB'], 'quantidade' => $quantidadeUsada,
            'valor_total' => $quantidadeUsada * 8, 'data' => '2025-06-01', 'created_at' => $agora, 'updated_at' => $agora]);

        return $ata;
    }

    public function test_aquisicoes_rp_formulas_e_sinalizadores(): void
    {
        $ata = $this->ataComUso(12, '2026-01-15');
        $f = new FiltrosAplicados(hoje: CarbonImmutable::parse('2026-01-01'));
        $r = app(AquisicoesRpService::class)->painel($f, []);
        $i = $r['indicadores'];

        $this->assertSame(100.0, $i['total_estimado']);
        $this->assertSame(80.0, $i['total_registrado']);
        $this->assertSame(96.0, $i['total_comprado']);
        $this->assertSame(20.0, $i['economia_estimada']);
        $this->assertSame(20.0, $i['percentual_economia']);
        $this->assertSame(24.0, $i['economia_efetiva']);      // 12 × (10 − 8)
        $this->assertSame(120.0, $i['percentual_utilizado']);
        $this->assertSame(1, $i['orgaos_participantes']);
        $this->assertSame(1, $i['participacoes']); // o gerenciador não conta como participação

        $linha = $r['por_ata'][0];
        $this->assertSame($ata, $linha['id']);
        $this->assertContains('ACIMA_100', $linha['sinalizadores']);
        $this->assertContains('VENCIMENTO_PROXIMO', $linha['sinalizadores']);
        $this->assertContains('ALTA_UTILIZACAO', $linha['sinalizadores']);
        $this->assertSame(-2.0, $r['por_item'][0]['quantidade_disponivel']);

        $part = collect($r['por_orgao'])->firstWhere('id', $this->ids['uB']);
        $this->assertSame(-100.0, $part['percentual_saldo']);       // previsto 48, adquirido 96
        $this->assertSame(120.0, $part['percentual_utilizado_ata']);

        // Selecionar o órgão gerenciador (sem uso) zera o comprado nas tabelas de atas e itens
        $sel = app(AquisicoesRpService::class)->painel($f, ['unidade_id' => $this->ids['uA']]);
        $this->assertSame(0.0, $sel['por_ata'][0]['total_comprado']);
    }

    public function test_licitacoes_rp_indicadores_e_comparacao_rp_sim_nao(): void
    {
        $this->ataComUso(2);
        $this->processo('2025-05-01', 'PREGAO', $this->ids['uB'], [['est' => 100, 'adj' => 90]]);
        $f = new FiltrosAplicados(hoje: CarbonImmutable::parse('2026-01-01'));
        $r = app(LicitacoesRpService::class)->painel($f);

        $this->assertSame(80.0, $r['indicadores']['total_adjudicado']);
        $this->assertSame(20.0, $r['indicadores']['percentual_economia']);
        $this->assertSame(1, $r['indicadores']['atas_vigentes']);
        $this->assertSame(1, $r['indicadores']['itens_registrados']);
        $this->assertSame(1, $r['indicadores']['gestores']);

        $pregao = $r['graficos']['pregao_rp'][0];
        $this->assertSame(47.06, $pregao['sim']); // 80 / 170
        $this->assertSame(52.94, $pregao['nao']);
        $eco = $r['graficos']['economia_pregao_rp'][0];
        $this->assertSame(20.0, $eco['sim']);
        $this->assertSame(10.0, $eco['nao']);
    }

    public function test_sistematica_situacoes_tempo_e_equivalencia(): void
    {
        $this->processo('2025-01-01', 'PREGAO', $this->ids['uA'], [['est' => 100]], ['data_homologacao' => '2025-01-31', 'quantidade_participantes' => 3]);
        $this->processo('2025-02-01', 'PREGAO', $this->ids['uA'], [['est' => 100, 'con' => null, 'situacao' => 'DESERTO']], ['situacao' => 'DESERTO', 'quantidade_participantes' => 0]);
        $mod = DB::table('classificacoes_modalidades')->where('codigo', 'PREGAO_10520')->value('id');
        $this->processo('2020-01-01', 'PREGAO', $this->ids['uA'], [['est' => 100]], ['modalidade_id' => $mod]);

        $r = app(SistematicaService::class)->painel(new FiltrosAplicados, new OpcoesCalculo);
        $pregao = collect($r['por_sistematica'])->firstWhere('nome', 'Pregão');

        $this->assertSame(3, $pregao['processos']);
        $this->assertSame(1, $pregao['situacoes']['DESERTO']);
        $this->assertSame(30.0, $pregao['tempo_medio_dias']);
        $this->assertSame(33.33, $pregao['percentual_disputa']);
        $this->assertNotEmpty($r['equivalencias']);
        $comparavel = collect($r['serie_comparavel']['linhas'])->firstWhere('ano', 2020);
        $this->assertSame('Pregão', $comparavel['modalidade']); // Pregão da 10.520 entra na série do pregão da 14.133
        $this->assertSame(1, $comparavel['regime_anterior']);
    }

    public function test_rotas_respeitam_permissoes_e_orgaos(): void
    {
        $this->processo('2025-03-01', 'PREGAO', $this->ids['uA'], [['est' => 100]]);
        $this->processo('2025-03-01', 'PREGAO', $this->ids['uB'], [['est' => 300]]);
        $admin = $this->usuario('ADMIN');
        foreach (['mpe', 'alimentos', 'licitacoes-rp', 'aquisicoes-rp', 'geografico', 'natureza', 'sistematica', 'licitacoes-rp/processos'] as $rota) {
            $this->actingAs($admin)->getJson("/api/inteligencia/$rota")->assertOk();
        }
        $bv = DB::table('municipios')->where('codigo_ibge', '1400100')->value('id');
        $this->actingAs($admin)->getJson("/api/inteligencia/municipios/$bv")->assertOk()->assertJsonPath('resumo.contratado', 400);
        $this->actingAs($admin)->getJson("/api/inteligencia/itens/{$this->ids['item']}")->assertOk()->assertJsonCount(2, 'processos');
        $this->actingAs($admin)->getJson('/api/inteligencia/municipios/999999')->assertNotFound();

        // Perfil restrito ao órgão A só enxerga as compras do órgão A
        $restrito = $this->usuario('GESTOR_ORGAO', [$this->ids['orgA']]);
        $this->actingAs($restrito)->getJson("/api/inteligencia/municipios/$bv")->assertOk()->assertJsonPath('resumo.contratado', 100);

        $geo = $this->actingAs($admin)->getJson('/api/inteligencia/geografico')->json();
        $this->assertCount(15, $geo['municipios']);
        $this->assertSame(400.0, (float) collect($geo['municipios'])->firstWhere('codigo_ibge', '1400100')['contratado']);
    }
}
