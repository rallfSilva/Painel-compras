<?php

namespace Tests\Feature;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Indicadores\IndicadorService;
use App\Services\Indicadores\OpcoesCalculo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CenarioCompras;
use Tests\TestCase;

/** Testes dos cálculos financeiros e percentuais (seção 34). */
class IndicadoresTest extends TestCase
{
    use CenarioCompras, RefreshDatabase;

    private IndicadorService $servico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->servico = app(IndicadorService::class);

        // Pregão 2025 na Unidade A: estimado 1.000, contratado 800 (economia 200)
        $this->processo('2025-03-10', 'PREGAO', $this->ids['uA'], [['est' => 1000, 'adj' => 800]]);
        // Dispensa 2025 na Unidade B: estimado 500, adjudicado 450, contratado 400 (fornecedor grande de SP)
        $this->processo('2025-08-20', 'DISPENSA', $this->ids['uB'], [['est' => 500, 'adj' => 450, 'con' => 400, 'fornecedor' => $this->ids['fGR']]]);
        // Chamada Pública PNAE 2025: estimado = contratado = 300, sem disputa de preço
        $this->processo('2025-05-05', 'CHAMADA_PNAE', $this->ids['uA'], [['est' => 300, 'fornecedor' => $this->ids['fPF']]]);
        // Pregão 2024: estimado 2.000, contratado 1.500; um item deserto que não entra no estimado
        $this->processo('2024-06-01', 'PREGAO', $this->ids['uA'], [['est' => 2000, 'adj' => 1500], ['est' => 999, 'adj' => null, 'con' => null, 'situacao' => 'DESERTO']]);
    }

    private function calcular(array $selecoes = [], ?OpcoesCalculo $o = null, string $periodo = 'todo', ?array $orgaos = null): array
    {
        $f = new FiltrosAplicados($periodo, null, null, $selecoes, $orgaos, CarbonImmutable::parse('2025-11-02'));

        return $this->servico->calcular($f, $o ?? new OpcoesCalculo);
    }

    public function test_grandezas_financeiras_sao_calculadas_separadamente(): void
    {
        $r = $this->calcular();

        $this->assertSame(3800.0, $r['total_estimado']); // 1000 + 500 + 300 + 2000 (deserto fora)
        $this->assertSame(3000.0, $r['total_contratado']); // 800 + 400 + 300 + 1500
        $this->assertSame(3050.0, $r['total_homologado']); // 800 + 450 + 300 + 1500
        $this->assertSame(3000.0, $r['total_licitado']); // só pregões: 1000 + 2000
    }

    public function test_economia_exclui_procedimentos_sem_disputa_por_padrao(): void
    {
        $r = $this->calcular();

        // Comparáveis com disputa: 1000→800, 500→400, 2000→1500. PNAE fica fora.
        $this->assertSame(800.0, $r['economia']);
        $this->assertSame(round(800 / 3500 * 100, 2), $r['percentual_economia']);
    }

    public function test_economia_inclui_sem_disputa_quando_solicitado(): void
    {
        $r = $this->calcular([], new OpcoesCalculo(incluirSemDisputa: true));

        $this->assertSame(800.0, $r['economia']); // PNAE soma 0 de economia
        $this->assertSame(round(800 / 3800 * 100, 2), $r['percentual_economia']); // mas entra no estimado comparável
    }

    public function test_base_de_comparacao_da_economia_e_configuravel(): void
    {
        $r = $this->calcular([], new OpcoesCalculo(baseEconomia: 'adjudicado'));

        $this->assertSame(750.0, $r['economia']); // 200 + 50 + 500
    }

    public function test_execucao_financeira_sem_dados_retorna_nao_informado(): void
    {
        $r = $this->calcular();

        $this->assertNull($r['total_empenhado']);
        $this->assertNull($r['total_liquidado']);
        $this->assertNull($r['total_pago']);
    }

    public function test_execucao_financeira_com_dados_soma_por_tipo(): void
    {
        $processo = DB::table('processos')->where('ano', 2025)->value('id');
        foreach ([['EMPENHO', 700], ['EMPENHO', 100], ['LIQUIDACAO', 500], ['PAGAMENTO', 450]] as [$tipo, $valor]) {
            DB::table('contratos_execucao_financeira')->insert(['processo_id' => $processo, 'tipo' => $tipo, 'data' => '2025-04-01', 'valor' => $valor, 'created_at' => now(), 'updated_at' => now()]);
        }

        $r = $this->calcular();

        $this->assertSame(800.0, $r['total_empenhado']);
        $this->assertSame(500.0, $r['total_liquidado']);
        $this->assertSame(450.0, $r['total_pago']);
    }

    public function test_contagens_de_processos_e_procedimentos(): void
    {
        $r = $this->calcular();

        $this->assertSame(4, $r['processos']);
        $this->assertSame(2, $r['qtd_licitacoes']);
        $this->assertSame(1, $r['qtd_dispensas']);
        $this->assertSame(1, $r['qtd_chamadas_publicas']);
        $this->assertSame(0, $r['qtd_inexigibilidades']);
        $this->assertSame(4, $r['itens_comprados']); // item deserto não conta
        $this->assertSame(3, $r['fornecedores']);
        $this->assertSame(2, $r['unidades_compradoras']);
    }

    public function test_filtros_combinados_alteram_o_resultado(): void
    {
        $r = $this->calcular(['tempo' => ['a:2025'], 'unidade' => ['u:'.$this->ids['uA']]]);
        $this->assertSame(1300.0, $r['total_estimado']); // pregão 1000 + PNAE 300

        $r = $this->calcular(['porte' => ['p:'.DB::table('portes_empresariais')->where('codigo', 'GRANDE')->value('id')]]);
        $this->assertSame(400.0, $r['total_contratado']);

        $r = $this->calcular(['fornecedor_local' => ['uf:SP']]);
        $this->assertSame(1, $r['processos']);

        $r = $this->calcular(['sistematica' => ['s:'.$this->ids['sist']['PREGAO']], 'tempo' => ['m:2024-06']]);
        $this->assertSame(1500.0, $r['total_contratado']);
    }

    public function test_filtro_com_valor_invalido_nao_retorna_tudo(): void
    {
        $r = $this->calcular(['unidade' => ['u:abc']]);

        $this->assertSame(0, $r['processos']);
        $this->assertNull($r['total_estimado']);
    }

    public function test_restricao_por_orgao(): void
    {
        $r = $this->calcular([], null, 'todo', [$this->ids['orgB']]);

        $this->assertSame(1, $r['processos']);
        $this->assertSame(400.0, $r['total_contratado']);
    }

    public function test_periodo_anterior_desloca_o_intervalo(): void
    {
        $f = new FiltrosAplicados('ano_atual', null, null, [], null, CarbonImmutable::parse('2025-11-02'));
        $anterior = $f->periodoAnterior();

        $this->assertSame('01/01/2024 a 31/12/2024', $anterior->descricaoPeriodo());
        $this->assertSame(2000.0, $this->servico->calcular($anterior, new OpcoesCalculo)['total_estimado']);

        $t = new FiltrosAplicados('todo', null, null, ['tempo' => ['a:2024', 'a:2025']], null);
        $this->assertSame(['a:2022', 'a:2023'], $t->periodoAnterior()->valores('tempo'));

        $this->assertNull((new FiltrosAplicados)->periodoAnterior());
    }

    public function test_contratos_vigentes_nao_dependem_do_periodo(): void
    {
        $processo2024 = DB::table('processos')->where('ano', 2024)->value('id');
        $base = ['processo_id' => $processo2024, 'unidade_compradora_id' => $this->ids['uA'], 'fornecedor_id' => $this->ids['fME'], 'objeto' => 'X', 'valor_inicial' => 10, 'vigencia_inicio' => '2024-07-01', 'created_at' => now(), 'updated_at' => now()];
        DB::table('contratos')->insert($base + ['uid' => 'C1', 'numero' => '1', 'vigencia_fim' => '2025-12-01', 'situacao' => 'VIGENTE']);
        DB::table('contratos')->insert($base + ['uid' => 'C2', 'numero' => '2', 'vigencia_fim' => '2026-06-01', 'situacao' => 'VIGENTE']);
        DB::table('contratos')->insert($base + ['uid' => 'C3', 'numero' => '3', 'vigencia_fim' => '2025-01-01', 'situacao' => 'VIGENTE']); // já venceu
        DB::table('contratos')->insert($base + ['uid' => 'C4', 'numero' => '4', 'vigencia_fim' => '2026-06-01', 'situacao' => 'RESCINDIDO']);

        // Período 2025 não inclui o processo de 2024, mas os contratos vigentes hoje continuam contando
        $r = $this->calcular(['tempo' => ['a:2025']]);

        $this->assertSame(2, $r['contratos_vigentes']);
        $this->assertSame(1, $r['contratos_vencendo']); // vence em até 90 dias
    }
}
