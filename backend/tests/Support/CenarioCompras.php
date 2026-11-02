<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Database\Seeders\AcessoSeeder;
use Database\Seeders\GlossarioSeeder;
use Database\Seeders\ReferenciaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Monta um cenário pequeno e controlado, com valores redondos, para conferir os cálculos à mão.
 */
trait CenarioCompras
{
    protected array $ids = [];

    protected function montarCenario(): void
    {
        $this->seed([ReferenciaSeeder::class, AcessoSeeder::class, GlossarioSeeder::class]);
        $agora = now();
        $rr = DB::table('estados')->where('uf', 'RR')->value('id');
        $bv = DB::table('municipios')->where('codigo_ibge', '1400100')->value('id');
        $sp = DB::table('municipios')->where('codigo_ibge', '3550308')->value('id');

        $us = DB::table('unidades_superiores')->insertGetId(['nome' => 'Direta', 'created_at' => $agora, 'updated_at' => $agora]);
        $orgA = DB::table('orgaos')->insertGetId(['nome' => 'Órgão A', 'sigla' => 'A', 'unidade_superior_id' => $us, 'created_at' => $agora, 'updated_at' => $agora]);
        $orgB = DB::table('orgaos')->insertGetId(['nome' => 'Órgão B', 'sigla' => 'B', 'unidade_superior_id' => $us, 'created_at' => $agora, 'updated_at' => $agora]);
        $uA = DB::table('unidades_compradoras')->insertGetId(['orgao_id' => $orgA, 'municipio_id' => $bv, 'nome' => 'Unidade A', 'created_at' => $agora, 'updated_at' => $agora]);
        $uB = DB::table('unidades_compradoras')->insertGetId(['orgao_id' => $orgB, 'municipio_id' => $bv, 'nome' => 'Unidade B', 'created_at' => $agora, 'updated_at' => $agora]);

        $porte = DB::table('portes_empresariais')->pluck('id', 'codigo');
        $fME = DB::table('fornecedores')->insertGetId(['documento' => '11111111000111', 'razao_social' => 'ME Local', 'porte_id' => $porte['ME'], 'municipio_id' => $bv, 'created_at' => $agora, 'updated_at' => $agora]);
        $fGR = DB::table('fornecedores')->insertGetId(['documento' => '22222222000122', 'razao_social' => 'Grande SP', 'porte_id' => $porte['GRANDE'], 'municipio_id' => $sp, 'created_at' => $agora, 'updated_at' => $agora]);
        $fPF = DB::table('fornecedores')->insertGetId(['documento' => '12345678901', 'tipo_pessoa' => 'F', 'razao_social' => 'Agricultor', 'porte_id' => $porte['PAF'], 'municipio_id' => $bv, 'created_at' => $agora, 'updated_at' => $agora]);

        $grupo = DB::table('grupos_materiais')->insertGetId(['codigo' => 'G1', 'nome' => 'Grupo 1', 'created_at' => $agora, 'updated_at' => $agora]);
        $classe = DB::table('classes_materiais')->insertGetId(['grupo_id' => $grupo, 'codigo' => 'C1', 'nome' => 'Classe 1', 'created_at' => $agora, 'updated_at' => $agora]);
        $item = DB::table('itens_catalogo')->insertGetId(['classe_id' => $classe, 'codigo' => '1001', 'descricao' => 'Item 1', 'created_at' => $agora, 'updated_at' => $agora]);

        $this->ids = compact('rr', 'orgA', 'orgB', 'uA', 'uB', 'fME', 'fGR', 'fPF', 'grupo', 'classe', 'item') + [
            'sist' => DB::table('sistematicas_aquisicao')->pluck('id', 'codigo')->all(),
        ];
    }

    /**
     * @param  list<array{est:float, adj?:float|null, con?:float|null, fornecedor?:int, situacao?:string}>  $itens
     */
    protected function processo(string $data, string $sistematica, int $unidade, array $itens, array $extra = []): int
    {
        $d = CarbonImmutable::parse($data);
        static $seq = 0;
        $seq++;
        $id = DB::table('processos')->insertGetId(array_merge([
            'uid' => "T-$seq", 'numero_publicacao' => sprintf('%d/%05d', $d->year, $seq),
            'ano' => $d->year, 'mes' => $d->month, 'trimestre' => intdiv($d->month - 1, 3) + 1, 'semestre' => $d->month <= 6 ? 1 : 2,
            'data_abertura' => $d->toDateString(), 'unidade_compradora_id' => $unidade, 'objeto' => 'Teste',
            'sistematica_id' => $this->ids['sist'][$sistematica], 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
        foreach ($itens as $n => $i) {
            $adj = array_key_exists('adj', $i) ? $i['adj'] : $i['est'];
            $con = array_key_exists('con', $i) ? $i['con'] : $adj;
            DB::table('processos_itens')->insert([
                'processo_id' => $id, 'numero_item' => $n + 1, 'item_catalogo_id' => $this->ids['item'], 'descricao' => 'Item',
                'valor_total_estimado' => $i['est'], 'valor_total_adjudicado' => $adj, 'valor_total_homologado' => $adj,
                'valor_total_contratado' => $con, 'fornecedor_id' => $i['fornecedor'] ?? $this->ids['fME'],
                'situacao' => $i['situacao'] ?? ($con !== null ? 'CONTRATADO' : 'HOMOLOGADO'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }
}
