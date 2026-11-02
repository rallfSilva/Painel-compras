<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use App\Services\Compras\FornecedoresService;
use App\Services\Compras\PanoramaService;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Indicadores\OpcoesCalculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CenarioCompras;
use Tests\TestCase;

/** Etapa 2: Panorama por unidade, processos, fornecedores e órgãos. */
class ComprasTest extends TestCase
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

    private function linhaPanorama(array $tabela, int $unidade): array
    {
        return collect($tabela['dados'])->firstWhere('id', $unidade);
    }

    public function test_colunas_do_panorama(): void
    {
        // Unidade A: pregão eletrônico com ME local (600) e Grande de SP (400); inexigibilidade presencial com ME local (200).
        $this->processo('2025-03-01', 'PREGAO', $this->ids['uA'], [
            ['est' => 800, 'con' => 600], ['est' => 500, 'con' => 400, 'fornecedor' => $this->ids['fGR']],
        ], ['eletronico' => true]);
        $this->processo('2025-04-01', 'INEXIGIBILIDADE', $this->ids['uA'], [['est' => 200, 'con' => 200]], ['eletronico' => false]);
        // Unidade B: um pregão de 800
        $this->processo('2025-05-01', 'PREGAO', $this->ids['uB'], [['est' => 1000, 'con' => 800, 'fornecedor' => $this->ids['fGR']]]);

        $svc = app(PanoramaService::class);
        $t = $svc->tabela(new FiltrosAplicados, new OpcoesCalculo);
        $a = $this->linhaPanorama($t, $this->ids['uA']);

        $this->assertSame(2000.0, $t['total_geral']);
        $this->assertSame(1200.0, $a['total_comprado']);
        $this->assertSame(60.0, $a['percentual_total']);
        $this->assertSame(2, $a['processos']);
        $this->assertSame(3, $a['itens']);
        $this->assertSame(1.5, $a['media_itens_processo']);
        // Economia só com disputa: (1300 − 1000) / 1300
        $this->assertSame(23.08, $a['percentual_economia']);
        $this->assertSame(2, $a['fornecedores']);
        $this->assertSame(66.67, $a['percentual_mpe']);      // 800 de ME / 1200
        $this->assertSame(66.67, $a['percentual_estado']);   // fornecedor de RR
        $this->assertSame(50.0, $a['percentual_disputa']);   // 1 de 2 processos
        $this->assertSame(50.0, $a['percentual_eletronicos']);

        $porValor = $this->linhaPanorama($svc->tabela(new FiltrosAplicados, new OpcoesCalculo, 'valor'), $this->ids['uA']);
        $this->assertSame(83.33, $porValor['percentual_disputa']); // 1000 / 1200
    }

    public function test_mediana_compara_com_as_demais_unidades(): void
    {
        $um = DB::table('unidades_medida')->value('id');
        $precos = [[$this->ids['uA'], 12.0], [$this->ids['uB'], 10.0], [$this->ids['uB'], 10.0], [$this->ids['uB'], 10.0]];
        foreach ($precos as [$u, $preco]) {
            $p = $this->processo('2025-06-01', 'PREGAO', $u, [['est' => $preco * 10]]);
            DB::table('processos_itens')->where('processo_id', $p)->update(['unidade_medida_id' => $um, 'valor_unitario_adjudicado' => $preco]);
        }
        $m = app(PanoramaService::class)->medianasPreco(new FiltrosAplicados);

        // A pagou 12 contra a mediana de 10 das demais: +20%. B é comparada só com A (1 preço < mínimo de 3): sem base.
        $this->assertSame(20.0, $m[$this->ids['uA']]['mediana']);
        $this->assertArrayNotHasKey($this->ids['uB'], $m);
    }

    public function test_grafico_agrupado_traz_filtro_cruzado(): void
    {
        $this->processo('2025-03-01', 'PREGAO', $this->ids['uA'], [['est' => 100]]);
        $this->processo('2025-03-01', 'INEXIGIBILIDADE', $this->ids['uB'], [['est' => 300]]);
        $user = $this->usuario('ADMIN');

        $r = $this->actingAs($user)->getJson('/api/compras/graficos')->assertOk();
        $orgao = collect($r->json('por_orgao'))->firstWhere('nome', 'B');
        $this->assertSame('unidade', $orgao['filtro']);
        $this->assertSame('o:'.$this->ids['orgB'], $orgao['chave']);
        $this->assertEquals(75, $orgao['percentual']);
        $this->assertSame(['COM', 'SEM'], array_column($r->json('com_sem_disputa'), 'grupo'));
    }

    public function test_processos_lista_resumo_e_ficha(): void
    {
        $p1 = $this->processo('2025-03-01', 'PREGAO', $this->ids['uA'], [['est' => 100]], ['data_homologacao' => '2025-03-11']);
        $this->processo('2025-03-05', 'PREGAO', $this->ids['uB'], [['est' => 100, 'adj' => null, 'situacao' => 'DESERTO']], ['situacao' => 'DESERTO']);
        DB::table('processos_fases')->insert(['processo_id' => $p1, 'fase' => 'PLANEJAMENTO', 'data_inicio' => '2025-01-01', 'data_fim' => '2025-01-10']);
        $admin = $this->usuario('ADMIN');

        $r = $this->actingAs($admin)->getJson('/api/processos?com_resumo=1&situacao[]=DESERTO')->assertOk();
        $this->assertSame(1, $r->json('total'));
        $this->assertSame(2, $r->json('resumo.total'));
        $this->assertEquals(10, $r->json('resumo.tempo_medio_conclusao_dias'));

        $f = $this->actingAs($admin)->getJson("/api/processos/$p1")->assertOk();
        $this->assertSame('concluida', $f->json('fases.0.estado'));
        $this->assertSame('pendente', $f->json('fases.1.estado'));
        $this->assertCount(11, $f->json('fases')); // pregão segue o rito completo

        // Gestor restrito ao órgão B não abre processo do órgão A
        $gestor = $this->usuario('GESTOR_ORGAO', [$this->ids['orgB']]);
        $this->actingAs($gestor)->getJson("/api/processos/$p1")->assertNotFound();
    }

    public function test_ficha_do_fornecedor_protege_dados_pessoais(): void
    {
        DB::table('fornecedores')->where('id', $this->ids['fPF'])->update(['email' => 'agricultor@exemplo.com']);
        $this->processo('2025-05-05', 'CHAMADA_PNAE', $this->ids['uA'], [['est' => 300, 'fornecedor' => $this->ids['fPF']]]);

        $r = $this->actingAs($this->usuario('CONSULTA_GERENCIAL'))->getJson('/api/fornecedores/'.$this->ids['fPF'])->assertOk();
        $this->assertSame('***.456.***-**', $r->json('fornecedor.documento'));
        $this->assertSame('Protegido (LGPD)', $r->json('fornecedor.email'));
        $this->assertEquals(300, $r->json('resumo.contratado'));

        $r = $this->actingAs($this->usuario('ADMIN'))->getJson('/api/fornecedores/'.$this->ids['fPF'])->assertOk();
        $this->assertSame('12345678901', $r->json('fornecedor.documento'));
    }

    public function test_concentracao_e_sinalizadores(): void
    {
        // Unidade A: 5 processos, ME com 900 e Grande com 100 → 90% com um fornecedor
        for ($i = 0; $i < 4; $i++) {
            $this->processo('2025-0'.($i + 1).'-01', 'PREGAO', $this->ids['uA'], [['est' => 225]]);
        }
        $this->processo('2025-06-01', 'PREGAO', $this->ids['uA'], [['est' => 100, 'fornecedor' => $this->ids['fGR']]]);

        $c = app(FornecedoresService::class)->concentracao(new FiltrosAplicados);
        $this->assertEquals(8200.0, $c['hhi']); // 90² + 10²
        $this->assertSame('ALTA', $c['classificacao_hhi']);
        $this->assertCount(1, $c['sinalizadores']);
        $this->assertSame(90.0, $c['sinalizadores'][0]['percentual']);
    }

    public function test_exportacao_amplia_pagina_so_com_permissao(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->processo('2025-03-01', 'PREGAO', $this->ids['uA'], [['est' => 10]]);
        }
        $admin = $this->usuario('ADMIN');
        $this->actingAs($admin)->getJson('/api/processos?por_pagina=5000&exportar=1')->assertJsonPath('por_pagina', 5000);
        $this->actingAs($admin)->getJson('/api/processos?por_pagina=5000')->assertJsonPath('por_pagina', 100);
        $this->actingAs($this->usuario('CONSULTA_PUBLICA'))->getJson('/api/processos')->assertForbidden();
    }

    public function test_arvore_de_orgaos(): void
    {
        $this->processo('2025-03-01', 'PREGAO', $this->ids['uA'], [['est' => 100]]);
        $this->processo('2025-03-01', 'PREGAO', $this->ids['uB'], [['est' => 300, 'fornecedor' => $this->ids['fGR']]]);

        $r = $this->actingAs($this->usuario('ADMIN'))->getJson('/api/orgaos/arvore')->assertOk();
        $us = $r->json('dados.0');
        $this->assertEquals(400, $us['contratado']);
        $this->assertSame(2, $us['fornecedores']);
        $this->assertSame('B', $us['orgaos'][0]['sigla']);
        $this->assertEquals(75, $us['orgaos'][0]['percentual']);
    }
}
