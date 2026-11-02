<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use App\Services\Importacao\Valor;
use App\Services\Integracoes\IntegracoesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\CenarioCompras;
use Tests\TestCase;

/** Etapa 5: importação de arquivos e integrações externas. */
class ImportacaoTest extends TestCase
{
    use CenarioCompras, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        Storage::fake('local');
    }

    private function usuario(string $perfil): User
    {
        return User::create(['name' => $perfil, 'email' => strtolower($perfil).'@teste.local', 'password' => 'segredo123', 'ativo' => true,
            'perfil_id' => Perfil::where('codigo', $perfil)->value('id')]);
    }

    /** CNPJ válido a partir dos 12 primeiros dígitos. */
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

    private function enviar(string $tipo, UploadedFile $arquivo): array
    {
        return $this->post('/api/importacoes', ['tipo' => $tipo, 'arquivo' => $arquivo], ['Accept' => 'application/json'])->assertCreated()->json();
    }

    public function test_conversao_de_valores(): void
    {
        $this->assertSame(1234.56, Valor::numero('1.234,56'));
        $this->assertSame(1234.56, Valor::numero('R$ 1.234,56'));
        $this->assertSame(1234.56, Valor::numero('1234.56'));
        $this->assertSame(1234567.0, Valor::numero('1.234.567'));
        $this->assertSame('2025-03-10', Valor::data('10/03/2025'));
        $this->assertSame('2025-03-10', Valor::data('2025-03-10T09:30:00'));
        $this->assertSame('2025-03-10', Valor::data(45726));
        $this->assertSame(self::cnpj('123456780001'), Valor::documento(self::cnpj('123456780001')));
        $this->assertSame(self::cnpj('023456780001'), Valor::documento(substr(self::cnpj('023456780001'), 1))); // zero perdido na planilha
        $this->assertTrue(Valor::booleano('Sim'));
        $this->expectException(\InvalidArgumentException::class);
        Valor::documento('12345678000100');
    }

    public function test_csv_mapeamento_validacao_duplicidade_fila_e_reprocessamento(): void
    {
        $this->actingAs($u = $this->usuario('OPERADOR_IMPORTACAO'), 'sanctum');
        $c1 = self::cnpj('333333330001');
        $c2 = self::cnpj('444444440001');
        $csv = "CNPJ;Razão Social;Porte;Município\n$c1;Empresa Um;ME;Boa Vista\n$c2;Empresa Dois;EPP;1400100\n$c1;Empresa Um Repetida;ME;Boa Vista\n12345678000100;Inválida;ME;Boa Vista\n";
        $r = $this->enviar('FORNECEDORES', UploadedFile::fake()->createWithContent('fornecedores.csv', mb_convert_encoding($csv, 'Windows-1252', 'UTF-8')));
        $this->assertSame('PENDENTE', $r['status']);
        $this->assertSame(['CNPJ' => 'documento', 'Razão Social' => 'razao_social', 'Porte' => 'porte', 'Município' => 'municipio'], $r['mapeamento']);

        // Sem o campo obrigatório mapeado, a validação recusa.
        $this->postJson("/api/importacoes/{$r['id']}/validar", ['mapeamento' => ['CNPJ' => 'documento']])->assertUnprocessable();
        $v = $this->postJson("/api/importacoes/{$r['id']}/validar", ['mapeamento' => $r['mapeamento']])->assertOk()->json();
        $this->assertSame(['total' => 4, 'validos' => 2, 'invalidos' => 2, 'duplicados' => 1, 'novos' => 2, 'atualizacoes' => 0],
            array_intersect_key($v['resumo'], array_flip(['total', 'validos', 'invalidos', 'duplicados', 'novos', 'atualizacoes'])));
        $erros = $this->getJson("/api/importacoes/{$r['id']}/erros")->assertOk()->json('dados');
        $this->assertSame([4, 5], array_column($erros, 'linha'));
        $this->assertStringContainsString('dígito verificador', $erros[1]['mensagem']);

        // Confirmação: a fila (síncrona nos testes) processa e registra o histórico.
        $f = $this->postJson("/api/importacoes/{$r['id']}/confirmar")->assertOk();
        $d = $this->getJson("/api/importacoes/{$r['id']}")->assertOk()->json();
        $this->assertSame('CONCLUIDO', $d['status']);
        $this->assertSame([2, 2, 1], [$d['total_importados'], $d['total_rejeitados'], $d['total_duplicados']]);
        $forn = DB::table('fornecedores')->where('documento', $c1)->first();
        $this->assertSame('Empresa Um', $forn->razao_social);
        $this->assertSame('IMPORTACAO', $forn->origem);
        $this->assertSame($r['id'], (int) $forn->importacao_id);
        $this->assertNotNull($forn->municipio_id);
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'importacao.concluir')->where('recurso_id', $r['id'])->exists());

        // Reprocessamento controlado: nova importação validada, agora com atualizações em vez de inclusões.
        $n = $this->postJson("/api/importacoes/{$r['id']}/reprocessar")->assertCreated()->json();
        $this->assertSame('VALIDADO', $n['status']);
        $this->assertSame($r['id'], $n['reprocessada_de_id']);
        $this->assertSame(2, $n['resumo']['atualizacoes']);
        $this->postJson("/api/importacoes/{$r['id']}/confirmar")->assertUnprocessable();
        $this->assertSame(2, $this->getJson('/api/importacoes')->assertOk()->json('total'));
    }

    public function test_planilha_xlsx_de_processos_contratos_aditivos_e_financeiro(): void
    {
        $this->actingAs($this->usuario('ADMIN'), 'sanctum');
        DB::table('unidades_compradoras')->where('id', $this->ids['uA'])->update(['codigo' => 'UA01']);
        $forn = self::cnpj('555555550001');
        $xlsx = function (array $linhas): UploadedFile {
            $p = new Spreadsheet;
            $p->getActiveSheet()->fromArray($linhas);
            $arq = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
            (new Xlsx($p))->save($arq);

            return new UploadedFile($arq, 'dados.xlsx', null, null, true);
        };
        $importar = function (string $tipo, UploadedFile $a) {
            $r = $this->enviar($tipo, $a);
            $v = $this->postJson("/api/importacoes/{$r['id']}/validar", ['mapeamento' => $r['mapeamento']])->assertOk()->json();
            $this->postJson("/api/importacoes/{$r['id']}/confirmar")->assertOk();

            return $v + ['final' => $this->getJson("/api/importacoes/{$r['id']}")->json()];
        };

        $p = $importar('PROCESSOS', $xlsx([
            ['Nº de publicação', 'Unidade compradora', 'Objeto', 'Data de abertura', 'Sistemática', 'Nº do item', 'Descrição do item', 'Quantidade estimada', 'Valor unitário estimado', 'Valor total adjudicado', 'Valor total contratado', 'CNPJ fornecedor', 'Fornecedor'],
            ['900/2025', 'UA01', 'Material de expediente', '15/04/2025', 'Pregão', 1, 'Papel A4', 100, '25,00', '2000', '2000', $forn, 'Papelaria RR'],
            ['900/2025', 'UA01', 'Material de expediente', '15/04/2025', 'Pregão', 2, 'Caneta', 500, '2,00', null, null, null, null],
            ['901/2025', 'Inexistente', 'X', '15/04/2025', 'Pregão', 1, 'Y', 1, 1, null, null, null, null],
        ]));
        $this->assertSame(2, $p['final']['total_importados']);
        $proc = DB::table('processos')->where('numero_publicacao', '900/2025')->first();
        $this->assertEquals(3500, $proc->valor_estimado);
        $this->assertEquals(2000, $proc->valor_contratado);
        $this->assertSame(2025, (int) $proc->ano);
        $this->assertSame('Papelaria RR', DB::table('fornecedores')->where('documento', $forn)->value('razao_social'));
        $this->assertTrue(DB::table('agregados_compras_mensal')->where('ano', 2025)->exists());

        $c = $importar('CONTRATOS', $xlsx([
            ['Nº do contrato', 'Unidade', 'CNPJ', 'Processo', 'Objeto', 'Valor inicial', 'Início da vigência', 'Fim da vigência'],
            ['10/2025', 'UA01', $forn, '900/2025', 'Fornecimento de papel', 2000, '2025-05-01', '2026-04-30'],
        ]));
        $this->assertSame(1, $c['final']['total_importados']);
        $importar('ADITIVOS', $xlsx([['Nº do contrato', 'Unidade', 'Nº do aditivo', 'Tipo', 'Valor', 'Novo fim da vigência'], ['10/2025', 'UA01', '1', 'Prazo e valor', 500, '2027-04-30']]));
        $importar('EXECUCAO_FINANCEIRA', $xlsx([['Tipo', 'Documento', 'Data', 'Valor', 'Nº do contrato', 'Unidade'],
            ['Empenho', '2025NE001', '02/05/2025', 2500, '10/2025', 'UA01'], ['Pagamento', '2025OB010', '10/06/2025', 800, '10/2025', 'UA01']]));

        $ct = DB::table('contratos')->where('numero', '10/2025')->first();
        $this->assertSame('2027-04-30', $ct->vigencia_fim);
        $d = $this->getJson("/api/contratos/$ct->id")->assertOk()->json();
        $this->assertEquals(25, $d['contrato']['percentual_acrescimo']);
        $this->assertEquals(['empenhado' => 2500, 'liquidado' => 0, 'pago' => 800], $d['financeiro']);
    }

    public function test_pncp_coleta_so_orgaos_cadastrados_e_nao_duplica(): void
    {
        $cnpjA = self::cnpj('666666660001');
        DB::table('orgaos')->where('id', $this->ids['orgA'])->update(['cnpj' => $cnpjA]);
        $forn = self::cnpj('777777770001');
        $compra = fn (string $cnpj, int $seq) => ['numeroControlePNCP' => "$cnpj-1-00000$seq/2025", 'orgaoEntidade' => ['cnpj' => $cnpj, 'razaoSocial' => 'Órgão'],
            'unidadeOrgao' => ['codigoUnidade' => '925001', 'nomeUnidade' => 'Unidade PNCP', 'codigoIbge' => '1400100'], 'anoCompra' => 2025, 'sequencialCompra' => $seq,
            'numeroCompra' => "9000$seq", 'processo' => "SEI-$seq", 'objetoCompra' => 'Aquisição de material', 'modalidadeId' => 6, 'srp' => true,
            'valorTotalEstimado' => 10000.5, 'valorTotalHomologado' => 9000, 'dataAberturaProposta' => '2025-03-10T09:00:00', 'dataPublicacaoPncp' => '2025-03-01T10:00:00', 'situacaoCompraId' => 1];
        Http::fake([
            'pncp.gov.br/api/consulta/v1/contratacoes/*' => Http::response(['data' => [$compra($cnpjA, 1), $compra(self::cnpj('888888880001'), 2)], 'paginasRestantes' => 0]),
            'pncp.gov.br/api/consulta/v1/contratos*' => Http::response(['data' => [[
                'numeroControlePNCP' => "$cnpjA-2-000001/2025", 'numeroContratoEmpenho' => 'CT 01/2025', 'unidadeOrgao' => ['codigoUnidade' => '925001', 'nomeUnidade' => 'Unidade PNCP'],
                'niFornecedor' => $forn, 'nomeRazaoSocialFornecedor' => 'Fornecedor PNCP', 'numeroControlePncpCompra' => "$cnpjA-1-000001/2025",
                'objetoContrato' => 'Contrato do pregão', 'valorInicial' => 9000, 'valorGlobal' => 9000, 'dataAssinatura' => '2025-04-01',
                'dataVigenciaInicio' => '2025-04-01', 'dataVigenciaFim' => '2026-03-31']], 'paginasRestantes' => 0]),
        ]);

        $this->actingAs($this->usuario('GESTOR_SELC'), 'sanctum');
        $lista = collect($this->getJson('/api/integracoes')->assertOk()->json('integracoes'))->keyBy('codigo');
        $this->assertSame(['PNCP', 'COMPRASGOV', 'FINANCEIRO_RR'], $lista->keys()->all());
        $this->postJson('/api/integracoes/PNCP/testar')->assertOk()->assertJsonPath('ok', true);

        for ($vez = 0; $vez < 2; $vez++) {
            $this->postJson('/api/integracoes/PNCP/sincronizar', ['de' => '2025-03-01', 'ate' => '2025-03-31'])->assertStatus(202);
        }
        $this->assertSame(1, DB::table('processos')->where('origem', 'PNCP')->count());
        $p = DB::table('processos')->where('origem', 'PNCP')->first();
        $this->assertSame("PNCP:$cnpjA-1-000001/2025", $p->uid);
        $this->assertSame('90001/2025', $p->numero_publicacao);
        $this->assertEquals(10000.5, $p->valor_estimado);
        $this->assertTrue((bool) $p->registro_precos);
        $this->assertSame($this->ids['orgA'], (int) DB::table('unidades_compradoras')->where('id', $p->unidade_compradora_id)->value('orgao_id'));
        $ct = DB::table('contratos')->where('origem', 'PNCP')->get();
        $this->assertCount(1, $ct);
        $this->assertSame((int) $p->id, (int) $ct[0]->processo_id);
        $log = DB::table('logs_sincronizacao')->orderByDesc('id')->first();
        $this->assertSame('CONCLUIDO', $log->status);
        $this->assertStringContainsString('1 registro(s) de outros órgãos ignorado(s)', $log->mensagem);
        $this->assertNotNull(DB::table('integracoes')->where('codigo', 'PNCP')->value('ultima_sincronizacao_em'));

        // Parâmetros validados; integração genérica sem URL não sincroniza.
        $this->putJson('/api/integracoes/COMPRASGOV', ['parametros' => ['url' => 'http://inseguro']])->assertUnprocessable();
        $this->postJson('/api/integracoes/COMPRASGOV/testar')->assertOk()->assertJsonPath('ok', false);
    }

    public function test_conector_json_configuravel_grava_execucao_financeira(): void
    {
        $p = $this->processo('2025-02-01', 'PREGAO', $this->ids['uA'], [['est' => 1000, 'adj' => 900, 'con' => 900]]);
        DB::table('processos')->where('id', $p)->update(['numero_publicacao' => '50/2025']);
        Http::fake(['financeiro.rr.gov.br/*' => Http::response(['itens' => [['fase' => 'EMPENHO', 'nr' => 'NE1', 'dt' => '2025-03-01', 'vl' => '900,00', 'proc' => '50/2025']]])]);
        $s = app(IntegracoesService::class);
        $s->salvar('FINANCEIRO_RR', ['ativa' => true, 'parametros' => ['url' => 'https://financeiro.rr.gov.br/api?de={dataInicial}', 'caminho_dados' => 'itens',
            'mapeamento' => ['tipo' => 'fase', 'documento' => 'nr', 'data' => 'dt', 'valor' => 'vl', 'processo' => 'proc']]], $this->usuario('ADMIN')->id);
        $s->agendar('FINANCEIRO_RR', null);
        $this->assertEquals(900, DB::table('contratos_execucao_financeira')->where('documento', 'NE1')->value('valor'));
        $this->assertSame('SISTEMA_ESTADUAL', DB::table('contratos_execucao_financeira')->value('origem'));
    }

    public function test_permissoes(): void
    {
        $this->actingAs($this->usuario('CONSULTA_GERENCIAL'), 'sanctum');
        $this->getJson('/api/importacoes')->assertForbidden();
        $this->getJson('/api/integracoes')->assertForbidden();
        $this->actingAs($this->usuario('OPERADOR_IMPORTACAO'), 'sanctum');
        $this->getJson('/api/importacoes/tipos')->assertOk()->assertJsonCount(12, 'tipos');
        $this->getJson('/api/integracoes')->assertForbidden();
        $this->post('/api/importacoes', ['tipo' => 'FORNECEDORES', 'arquivo' => UploadedFile::fake()->create('x.exe', 1)], ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
