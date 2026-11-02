<?php

namespace Database\Seeders;

use App\Services\AgregacaoService;
use App\Support\FasesProcesso;
use App\Support\Normalizador;
use App\Support\Rastreabilidade;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DADOS DEMONSTRATIVOS FICTÍCIOS (seção 34).
 *
 * Todos os registros recebem origem = DEMONSTRATIVO e ficticio = true, e a interface exibe um aviso
 * enquanto houver dados fictícios. Nomes de órgãos são genéricos e todos os valores são aleatórios.
 * Para remover: php artisan sic:limpar-demonstrativos
 */
class DemonstrativoSeeder extends Seeder
{
    private const ORIGEM = Rastreabilidade::ORIGEM_DEMONSTRATIVO;

    private CarbonImmutable $agora;

    public function run(): void
    {
        mt_srand(20261102);
        $this->agora = CarbonImmutable::now();

        $this->regioes();
        $unidades = $this->orgaosEUnidades();
        $itens = $this->catalogo();
        $fornecedores = $this->fornecedores();
        $this->processos($unidades, $itens, $fornecedores);
        app(AgregacaoService::class)->recalcular();
    }

    private function base(): array
    {
        return ['origem' => self::ORIGEM, 'ficticio' => true, 'created_at' => $this->agora, 'updated_at' => $this->agora];
    }

    /** Regiões fictícias, só para demonstrar o filtro. Substituir pelas regiões oficiais de planejamento. */
    private function regioes(): array
    {
        $rr = DB::table('estados')->where('uf', 'RR')->value('id');
        $mapa = [
            'Região Demonstrativa Centro' => ['1400100', '1400175', '1400308', '1400050'],
            'Região Demonstrativa Norte' => ['1400027', '1400456', '1400704', '1400407', '1400159'],
            'Região Demonstrativa Sul' => ['1400472', '1400506', '1400605', '1400233'],
            'Região Demonstrativa Centro-Sul' => ['1400209', '1400282'],
        ];
        $ids = [];
        foreach ($mapa as $nome => $municipios) {
            $id = DB::table('regioes_planejamento')->insertGetId(['estado_id' => $rr, 'nome' => $nome] + $this->base());
            DB::table('municipios')->whereIn('codigo_ibge', $municipios)->update(['regiao_planejamento_id' => $id]);
            $ids[] = $id;
        }

        return $ids;
    }

    /** @return list<array{id:int, peso:int, saude:bool, educacao:bool}> */
    private function orgaosEUnidades(): array
    {
        $mun = DB::table('municipios')->join('estados', 'estados.id', '=', 'municipios.estado_id')
            ->where('estados.uf', 'RR')->pluck('municipios.id', 'municipios.codigo_ibge')->all();
        $bv = $mun['1400100'];

        $superiores = [];
        foreach ([['ESTADO', 'Administração Direta do Estado'], ['FES', 'Fundo Estadual de Saúde (fictício)'],
            ['INDIRETA', 'Administração Indireta']] as [$sigla, $nome]) {
            $superiores[$sigla] = DB::table('unidades_superiores')->insertGetId(['sigla' => $sigla, 'codigo' => 'DEMO-'.$sigla, 'nome' => $nome] + $this->base());
        }

        // [sigla, nome, tipo, superior, peso de compras, unidades extras]
        $orgaos = [
            ['SES', 'Secretaria de Saúde (fictícia)', 'SECRETARIA', 'FES', 30, ['Hospital Geral (fictício)', 'Hospital Materno-Infantil (fictício)', 'Hospital Regional Sul (fictício)']],
            ['SED', 'Secretaria de Educação (fictícia)', 'SECRETARIA', 'ESTADO', 22, ['Escola Estadual Indígena (fictícia)', 'Escola Estadual Centro (fictícia)', 'Escola Estadual Sul (fictícia)']],
            ['SSP', 'Secretaria de Segurança Pública (fictícia)', 'SECRETARIA', 'ESTADO', 10, ['Polícia Militar (fictícia)']],
            ['SEINF', 'Secretaria de Infraestrutura (fictícia)', 'SECRETARIA', 'ESTADO', 12, []],
            ['SEAGRI', 'Secretaria de Agricultura (fictícia)', 'SECRETARIA', 'ESTADO', 5, []],
            ['SEAD', 'Secretaria de Administração (fictícia)', 'SECRETARIA', 'ESTADO', 6, []],
            ['SEPLAN', 'Secretaria de Planejamento (fictícia)', 'SECRETARIA', 'ESTADO', 3, []],
            ['SEFAZ', 'Secretaria da Fazenda (fictícia)', 'SECRETARIA', 'ESTADO', 4, []],
            ['SEJUC', 'Secretaria de Justiça e Cidadania (fictícia)', 'SECRETARIA', 'ESTADO', 5, []],
            ['UNIEST', 'Universidade Estadual (fictícia)', 'FUNDACAO', 'INDIRETA', 4, []],
            ['DETRAN', 'Departamento de Trânsito (fictício)', 'AUTARQUIA', 'INDIRETA', 4, []],
            ['CAER', 'Companhia de Águas e Esgotos (fictícia)', 'EMPRESA_PUBLICA', 'INDIRETA', 5, []],
        ];

        $municipiosInterior = array_values(array_diff($mun, [$bv]));
        $unidades = [];
        $n = 0;
        foreach ($orgaos as [$sigla, $nome, $tipo, $sup, $peso, $extras]) {
            $oid = DB::table('orgaos')->insertGetId([
                'unidade_superior_id' => $superiores[$sup], 'codigo' => 'DEMO-'.$sigla, 'sigla' => $sigla, 'nome' => $nome, 'tipo' => $tipo,
            ] + $this->base());
            $lista = array_merge([$nome.' — Sede'], $extras);
            foreach ($lista as $i => $un) {
                $n++;
                $municipio = $i === 0 ? $bv : $municipiosInterior[($n * 3) % count($municipiosInterior)];
                $uid = DB::table('unidades_compradoras')->insertGetId([
                    'orgao_id' => $oid, 'municipio_id' => $municipio, 'codigo' => sprintf('DEMO-%04d', $n),
                    'uasg' => (string) (920000 + $n), 'nome' => $i === 0 ? $sigla.' — Sede' : $un,
                ] + $this->base());
                $unidades[] = ['id' => $uid, 'peso' => max(1, intdiv($peso, count($lista))), 'saude' => $sigla === 'SES', 'educacao' => $sigla === 'SED'];
            }
        }

        return $unidades;
    }

    /** @return list<array<string,mixed>> */
    private function catalogo(): array
    {
        $cat = DB::table('catalogos')->pluck('id', 'codigo');
        $nat = DB::table('classificacoes_natureza')->pluck('id', 'codigo');
        $sub = DB::table('subelementos_despesa')->pluck('id', 'codigo');
        $um = DB::table('unidades_medida')->pluck('id', 'sigla');

        // grupo => [tipo, natureza, subelemento, [classe => [[descrição, unidade, preço base], ...]]]
        $estrutura = [
            'Gêneros alimentícios' => ['M', 'ALIMENTOS', '3.3.90.30.07', [
                'Frutas, verduras e legumes' => [['BANANA, PRATA, FRESCA', 'KG', 6], ['LARANJA, PERA, TAMANHO MÉDIO', 'KG', 7], ['TOMATE, TAMANHO MÉDIO', 'KG', 9], ['ALFACE, CRESPA', 'UN', 3], ['ABÓBORA, MADURA', 'KG', 6], ['CENOURA, SEM FOLHAS', 'KG', 7]],
                'Carnes, aves e peixes' => [['CARNE BOVINA, PATINHO, CONGELADA', 'KG', 48], ['FRANGO, PEITO, CONGELADO', 'KG', 22], ['PEIXE, TAMBAQUI, POSTA', 'KG', 30]],
                'Cereais e grãos' => [['ARROZ, TIPO 1, PACOTE 5 KG', 'PCT', 28], ['FEIJÃO, CARIOCA, TIPO 1', 'KG', 9], ['FARINHA DE MANDIOCA, SECA', 'KG', 8]],
                'Laticínios' => [['LEITE, INTEGRAL, UHT', 'L', 6], ['QUEIJO, MUSSARELA', 'KG', 45]],
            ]],
            'Medicamentos e materiais hospitalares' => ['M', 'SAUDE', '3.3.90.30.09', [
                'Medicamentos' => [['DIPIRONA SÓDICA, 500 MG/ML, SOLUÇÃO INJETÁVEL', 'UN', 2], ['AMOXICILINA, 500 MG, CÁPSULA', 'UN', 0.6], ['SORO FISIOLÓGICO, 0,9%, 500 ML', 'FR', 5], ['INSULINA HUMANA NPH, 100 UI/ML', 'FR', 28]],
                'Material médico-hospitalar' => [['LUVA DE PROCEDIMENTO, LÁTEX, TAMANHO M', 'CX', 32], ['SERINGA DESCARTÁVEL, 5 ML', 'UN', 0.5], ['GAZE HIDRÓFILA, 7,5 X 7,5 CM', 'PCT', 4], ['ABAIXADOR DE LÍNGUA, MADEIRA', 'PCT', 9]],
            ]],
            'Material de expediente' => ['M', 'CONSUMO', '3.3.90.30.16', [
                'Papelaria' => [['PAPEL A4, 75 G/M2, RESMA 500 FOLHAS', 'RESMA', 28], ['CANETA ESFEROGRÁFICA, AZUL', 'CX', 35], ['GRAMPEADOR DE MESA', 'UN', 25]],
            ]],
            'Material de limpeza' => ['M', 'CONSUMO', '3.3.90.30.22', [
                'Produtos de limpeza' => [['ÁGUA SANITÁRIA, 1 L', 'L', 4], ['DETERGENTE LÍQUIDO, NEUTRO, 500 ML', 'UN', 3], ['SACO PARA LIXO, 100 L', 'PCT', 18]],
            ]],
            'Equipamentos de informática' => ['M', 'TI', '4.4.90.52.35', [
                'Computadores' => [['MICROCOMPUTADOR, DESKTOP, 16 GB RAM', 'UN', 4800], ['NOTEBOOK, 14 POLEGADAS, 16 GB RAM', 'UN', 5600], ['MONITOR, LED, 24 POLEGADAS', 'UN', 950]],
                'Periféricos' => [['IMPRESSORA MULTIFUNCIONAL, LASER', 'UN', 2300], ['NOBREAK, 1,5 KVA', 'UN', 1200]],
            ]],
            'Mobiliário' => ['M', 'PERMANENTE', '4.4.90.52.42', [
                'Mobiliário de escritório' => [['CADEIRA GIRATÓRIA, ESPALDAR MÉDIO', 'UN', 650], ['MESA DE ESCRITÓRIO, 1,20 M', 'UN', 780], ['ARMÁRIO DE AÇO, 2 PORTAS', 'UN', 1100]],
            ]],
            'Equipamentos médico-hospitalares' => ['M', 'EQUIPAMENTOS', '4.4.90.52.08', [
                'Equipamentos hospitalares' => [['MONITOR MULTIPARAMÉTRICO', 'UN', 18000], ['CAMA HOSPITALAR, ELÉTRICA', 'UN', 9500]],
            ]],
            'Serviços de apoio administrativo' => ['S', 'SERVICOS', '3.3.90.37.01', [
                'Mão de obra terceirizada' => [['SERVIÇO DE LIMPEZA E CONSERVAÇÃO', 'MES', 85000], ['SERVIÇO DE VIGILÂNCIA ARMADA', 'MES', 120000], ['SERVIÇO DE RECEPÇÃO E APOIO', 'MES', 60000]],
            ]],
            'Serviços de TI' => ['S', 'TI', '3.3.90.40.11', [
                'Serviços de tecnologia' => [['LOCAÇÃO DE EQUIPAMENTOS DE TI', 'MES', 45000], ['SUPORTE TÉCNICO DE INFRAESTRUTURA', 'MES', 70000]],
            ]],
            'Serviços de engenharia' => ['S', 'ENGENHARIA', '3.3.90.39.16', [
                'Manutenção predial' => [['MANUTENÇÃO PREDIAL PREVENTIVA E CORRETIVA', 'MES', 95000], ['REFORMA DE UNIDADE ESCOLAR', 'SERV', 850000]],
            ]],
            'Obras' => ['S', 'OBRAS', '4.4.90.51.91', [
                'Obras de edificação' => [['CONSTRUÇÃO DE UNIDADE BÁSICA DE SAÚDE', 'SERV', 2800000], ['PAVIMENTAÇÃO ASFÁLTICA DE VIA', 'M2', 120]],
            ]],
        ];

        $itens = [];
        $g = 0;
        $codigo = 100000;
        foreach ($estrutura as $grupo => [$tipo, $natureza, $subelemento, $classes]) {
            $g++;
            $gid = DB::table('grupos_materiais')->insertGetId([
                'catalogo_id' => $cat[$tipo === 'M' ? 'CATMAT' : 'CATSER'], 'tipo' => $tipo, 'codigo' => sprintf('G%02d', $g), 'nome' => mb_strtoupper($grupo),
            ] + $this->base());
            $c = 0;
            foreach ($classes as $classe => $lista) {
                $c++;
                $cid = DB::table('classes_materiais')->insertGetId([
                    'grupo_id' => $gid, 'codigo' => sprintf('G%02dC%02d', $g, $c), 'nome' => mb_strtoupper($classe),
                ] + $this->base());
                foreach ($lista as [$descricao, $unidade, $preco]) {
                    $codigo += mt_rand(7, 97);
                    $iid = DB::table('itens_catalogo')->insertGetId([
                        'classe_id' => $cid, 'classificacao_natureza_id' => $nat[$natureza], 'subelemento_despesa_id' => $sub[$subelemento],
                        'unidade_medida_id' => $um[$unidade], 'tipo' => $tipo, 'codigo' => (string) $codigo,
                        'codigo_sufixo' => $codigo.'-'.$um[$unidade], 'descricao' => $descricao, 'descricao_normalizada' => Normalizador::descricao($descricao),
                    ] + self::categoriaAlimento($descricao) + $this->base());
                    $itens[] = [
                        'id' => $iid, 'codigo' => (string) $codigo, 'descricao' => $descricao, 'unidade_id' => $um[$unidade],
                        'preco' => (float) $preco, 'grupo' => $grupo, 'natureza' => $nat[$natureza], 'natureza_codigo' => $natureza,
                        'subelemento' => $sub[$subelemento], 'tipo' => $tipo,
                    ];
                }
            }
        }

        $this->variantesCatalogo($itens);

        return $itens;
    }

    /**
     * Itens cadastrados em duplicidade ou com descrição parecida, sem compras, para demonstrar a busca de
     * duplicidades e semelhantes do Catálogo (seção 17). Nenhum é unido automaticamente.
     */
    private function variantesCatalogo(array $itens): void
    {
        $variantes = [
            'BANANA, PRATA, FRESCA' => ['Banana prata - fresca', null],
            'ARROZ, TIPO 1, PACOTE 5 KG' => ['ARROZ TIPO 1 PCT 5KG', null],
            'LUVA DE PROCEDIMENTO, LÁTEX, TAMANHO M' => ['LUVA DE PROCEDIMENTO, LÁTEX, TAMANHO G', 'Tamanho G, caixa com 100 unidades'],
            'SORO FISIOLÓGICO, 0,9%, 500 ML' => ['SORO FISIOLÓGICO, 0,9%, 250 ML', 'Frasco de 250 ml'],
        ];
        $porDescricao = collect($itens)->keyBy('descricao');
        $codigo = (int) collect($itens)->max(fn ($i) => (int) $i['codigo']);
        foreach ($variantes as $base => [$descricao, $especificacao]) {
            $b = DB::table('itens_catalogo')->where('id', $porDescricao[$base]['id'] ?? 0)->first();
            if (! $b) {
                continue;
            }
            $codigo += 11;
            DB::table('itens_catalogo')->insert([
                'classe_id' => $b->classe_id, 'classificacao_natureza_id' => $b->classificacao_natureza_id, 'subelemento_despesa_id' => $b->subelemento_despesa_id,
                'unidade_medida_id' => $b->unidade_medida_id, 'tipo' => $b->tipo, 'codigo' => (string) $codigo, 'codigo_sufixo' => $codigo.'-'.$b->unidade_medida_id,
                'descricao' => $descricao, 'descricao_normalizada' => Normalizador::descricao($descricao), 'especificacao' => $especificacao,
                'categoria_alimento' => $b->categoria_alimento, 'perecivel' => $b->perecivel,
            ] + $this->base());
        }
    }

    /** @return array<string, list<int>> fornecedores por perfil */
    private function fornecedores(): array
    {
        $portes = DB::table('portes_empresariais')->pluck('id', 'codigo');
        $rr = DB::table('municipios')->join('estados', 'estados.id', '=', 'municipios.estado_id')->where('estados.uf', 'RR')->pluck('municipios.id')->all();
        $fora = DB::table('municipios')->join('estados', 'estados.id', '=', 'municipios.estado_id')->where('estados.uf', '!=', 'RR')->pluck('municipios.id')->all();

        $prefixos = ['Comercial', 'Distribuidora', 'Serviços', 'Tecnologia', 'Construtora', 'Indústria', 'Alimentos', 'Hospitalar'];
        $sufixos = ['Norte', 'Amazônia', 'Monte Roraima', 'Rio Branco', 'Caracaranã', 'Tepequém', 'Macuxi', 'Boa Vista', 'Brasil', 'Equatorial'];
        $perfis = ['geral' => [], 'paf' => []];
        $rows = [];
        for ($i = 1; $i <= 140; $i++) {
            $sorteio = mt_rand(1, 100);
            $porte = match (true) {
                $sorteio <= 12 => 'MEI', $sorteio <= 40 => 'ME', $sorteio <= 62 => 'EPP', $sorteio <= 77 => 'MEDIO',
                $sorteio <= 92 => 'GRANDE', default => 'NI',
            };
            $local = mt_rand(1, 100) <= 58;
            $nome = sprintf('%s %s %s LTDA (FICTÍCIA)', $prefixos[$i % count($prefixos)], $sufixos[($i * 7) % count($sufixos)], chr(65 + $i % 26));
            $rows[] = [
                'documento' => sprintf('99%06d0001%02d', $i, $i % 97), 'tipo_pessoa' => 'J', 'razao_social' => $nome,
                'porte_id' => $portes[$porte], 'municipio_id' => $local ? $rr[array_rand($rr)] : $fora[array_rand($fora)],
                'situacao_cadastral' => 'ATIVA',
            ] + $this->base();
        }
        // Cooperativas e agricultores familiares (Chamada Pública PNAE)
        for ($i = 1; $i <= 18; $i++) {
            $coop = $i <= 8;
            $rows[] = [
                'documento' => $coop ? sprintf('98%06d0001%02d', $i, $i) : sprintf('9%010d', $i),
                'tipo_pessoa' => $coop ? 'J' : 'F',
                'razao_social' => $coop ? sprintf('COOPERATIVA DA AGRICULTURA FAMILIAR %s (FICTÍCIA)', $sufixos[$i % count($sufixos)]) : sprintf('AGRICULTOR FAMILIAR %02d (FICTÍCIO)', $i),
                'porte_id' => $portes['PAF'], 'municipio_id' => $rr[array_rand($rr)], 'situacao_cadastral' => 'ATIVA',
            ] + $this->base();
        }
        DB::table('fornecedores')->insert($rows);
        $todos = DB::table('fornecedores')->where('ficticio', true)->get(['id', 'porte_id']);
        foreach ($todos as $f) {
            $perfis[$f->porte_id == $portes['PAF'] ? 'paf' : 'geral'][] = (int) $f->id;
        }

        return $perfis;
    }

    private function processos(array $unidades, array $itens, array $fornecedores): void
    {
        $sist = DB::table('sistematicas_aquisicao')->get()->keyBy('codigo');
        $formas = DB::table('formas_aquisicao')->get()->groupBy('sistematica_id');
        $amparo = DB::table('amparos_legais')->pluck('id', 'codigo');
        $regime = DB::table('regimes_legais')->pluck('id', 'codigo');
        $mod = DB::table('classificacoes_modalidades')->pluck('id', 'codigo');

        $pesoUnidades = [];
        foreach ($unidades as $u) {
            for ($k = 0; $k < $u['peso']; $k++) {
                $pesoUnidades[] = $u;
            }
        }
        $alimentos = array_values(array_filter($itens, fn ($i) => $i['natureza_codigo'] === 'ALIMENTOS'));
        $hoje = CarbonImmutable::parse('2026-10-30');
        $seq = 0;
        $seqAta = 0;
        $seqContrato = 0;

        foreach (range(2019, 2026) as $ano) {
            $qtd = 150 + ($ano - 2019) * 12 + mt_rand(-10, 10);
            $mesMax = $ano === 2026 ? 10 : 12;
            for ($p = 0; $p < $qtd; $p++) {
                $seq++;
                $u = $pesoUnidades[array_rand($pesoUnidades)];
                $mes = mt_rand(1, $mesMax);
                $dia = mt_rand(1, $ano === 2026 && $mes === 10 ? 30 : 28);
                $data = CarbonImmutable::create($ano, $mes, $dia);
                $nova = $ano >= 2023; // Lei 14.133 obrigatória a partir de 2024; transição em 2023

                $r = mt_rand(1, 100);
                $codigoSist = match (true) {
                    $u['educacao'] && $r <= 18 => 'CHAMADA_PNAE',
                    $r <= 52 => 'PREGAO', $r <= 58 => 'CONCORRENCIA', $r <= 82 => 'DISPENSA',
                    $r <= 90 => 'INEXIGIBILIDADE', $r <= 94 => 'ADESAO_ATA', $r <= 97 => 'CREDENCIAMENTO', default => 'PREGAO',
                };
                $s = $sist[$codigoSist];
                $forma = $formas[$s->id][$codigoSist === 'PREGAO' && $ano <= 2020 && mt_rand(1, 100) <= 20 ? 1 : 0] ?? $formas[$s->id][0];
                $eletronico = ! str_contains($forma->codigo, 'PRESENCIAL');
                $rp = $codigoSist === 'PREGAO' && mt_rand(1, 100) <= 40;
                [$amp, $reg, $modalidade] = match ($codigoSist) {
                    'PREGAO' => $nova ? ['L14133_ART28_I', 'L14133', 'PREGAO_14133'] : ['L10520_ART1', 'L10520', 'PREGAO_10520'],
                    'CONCORRENCIA' => $nova ? ['L14133_ART28_II', 'L14133', 'CONCORRENCIA_14133'] : [null, 'L8666', 'CONCORRENCIA_8666'],
                    'DISPENSA' => $nova ? [mt_rand(0, 9) ? 'L14133_ART75_II' : 'L14133_ART75_VIII', 'L14133', null] : ['L8666_ART24_II', 'L8666', null],
                    'INEXIGIBILIDADE' => $nova ? ['L14133_ART74', 'L14133', null] : ['L8666_ART25', 'L8666', null],
                    'CHAMADA_PNAE' => ['L11947_ART14', 'L11947', null],
                    'CREDENCIAMENTO' => ['L14133_ART79', 'L14133', null],
                    'ADESAO_ATA' => ['L14133_ART86', 'L14133', null],
                    default => [null, 'OUTRO', null],
                };

                $sitR = mt_rand(1, 100);
                $situacao = $codigoSist === 'PREGAO' || $codigoSist === 'CONCORRENCIA'
                    ? match (true) {
                        $sitR <= 4 => 'DESERTO', $sitR <= 7 => 'FRACASSADO', $sitR <= 9 => 'REVOGADO', default => 'CONCLUIDO'
                    }
                : 'CONCLUIDO';
                if ($data->greaterThan($hoje->subDays(40)) && mt_rand(1, 100) <= 50) {
                    $situacao = 'EM_ANDAMENTO';
                }

                $pool = $codigoSist === 'CHAMADA_PNAE' ? $alimentos : $itens;
                if ($u['saude'] && $codigoSist !== 'CHAMADA_PNAE' && mt_rand(1, 100) <= 60) {
                    $pool = array_values(array_filter($itens, fn ($i) => in_array($i['natureza_codigo'], ['SAUDE', 'EQUIPAMENTOS'], true)));
                }
                $base = $pool[array_rand($pool)];
                $qtdItens = $base['tipo'] === 'S' ? mt_rand(1, 2) : mt_rand(1, $codigoSist === 'CHAMADA_PNAE' ? 12 : 8);
                $mesmosGrupo = array_values(array_filter($pool, fn ($i) => $i['grupo'] === $base['grupo']));

                $processoId = DB::table('processos')->insertGetId([
                    'uid' => sprintf('DEMO-%d-%05d', $ano, $seq),
                    'numero_publicacao' => sprintf('%d/%05d', $ano, $seq),
                    'numero_processo_sei' => sprintf('DEMO.%04d.%06d/%d', mt_rand(1, 9999), $seq, $ano),
                    'numero_licitacao' => in_array($codigoSist, ['PREGAO', 'CONCORRENCIA'], true) ? sprintf('%03d/%d', $p + 1, $ano) : null,
                    'ano' => $ano, 'mes' => $mes, 'trimestre' => intdiv($mes - 1, 3) + 1, 'semestre' => $mes <= 6 ? 1 : 2,
                    'data_abertura' => $data, 'data_publicacao' => $data->subDays(mt_rand(5, 15)),
                    'data_homologacao' => $situacao === 'CONCLUIDO' ? $data->addDays(mt_rand(10, 40)) : null,
                    'unidade_compradora_id' => $u['id'],
                    'objeto' => ($rp ? 'REGISTRO DE PREÇOS PARA FUTURAS E EVENTUAIS AQUISIÇÕES DE ' : 'AQUISIÇÃO DE ').mb_strtoupper($base['grupo']).' (DADO FICTÍCIO)',
                    'regime_legal_id' => $regime[$reg], 'modalidade_id' => $modalidade ? $mod[$modalidade] : null,
                    'sistematica_id' => $s->id, 'forma_aquisicao_id' => $forma->id, 'amparo_legal_id' => $amp ? $amparo[$amp] : null,
                    'classificacao_natureza_id' => $base['natureza'],
                    'criterio_julgamento' => in_array($codigoSist, ['PREGAO', 'CONCORRENCIA'], true) ? 'MENOR PREÇO' : null,
                    'eletronico' => $eletronico, 'registro_precos' => $rp, 'julgamento_lote' => mt_rand(1, 100) <= 15,
                    'situacao' => $situacao, 'quantidade_participantes' => $s->com_disputa ? mt_rand(1, 14) : 1,
                    'data_sessao' => in_array($codigoSist, ['PREGAO', 'CONCORRENCIA'], true) ? $data : null,
                    'responsavel' => sprintf('Servidor Demonstrativo %02d', mt_rand(1, 40)),
                    'agente_contratacao' => in_array($codigoSist, ['PREGAO', 'CONCORRENCIA', 'DISPENSA'], true) ? sprintf('Agente de Contratação Demonstrativo %02d', mt_rand(1, 25)) : null,
                ] + $this->base());

                $linhas = [];
                $somas = ['est' => 0, 'adj' => 0, 'hom' => 0, 'con' => 0];
                $vencedor = null;
                $temResultado = in_array($situacao, ['CONCLUIDO'], true);
                for ($k = 1; $k <= $qtdItens; $k++) {
                    $item = $mesmosGrupo[array_rand($mesmosGrupo)];
                    $inflacao = 1 + ($ano - 2019) * 0.055;
                    $unitEst = round($item['preco'] * $inflacao * (mt_rand(85, 125) / 100), 4);
                    $quant = $item['tipo'] === 'S' ? mt_rand(6, 12) : ($item['preco'] > 1000 ? mt_rand(2, 60) : mt_rand(50, 5000));
                    $totEst = round($unitEst * $quant, 2);
                    $itemDeserto = $temResultado && mt_rand(1, 100) <= 4;
                    $comResultado = $temResultado && ! $itemDeserto;
                    $desconto = $s->com_disputa ? mt_rand(0, 38) / 100 : 0;
                    $unitAdj = $comResultado ? round($unitEst * (1 - $desconto), 4) : null;
                    $totAdj = $comResultado ? round($unitAdj * $quant, 2) : null;
                    $contratado = $comResultado && mt_rand(1, 100) <= 93 ? $totAdj : null;
                    if ($vencedor === null || mt_rand(1, 100) <= 30) {
                        $lista = $codigoSist === 'CHAMADA_PNAE' ? $fornecedores['paf'] : $fornecedores['geral'];
                        $vencedor = $lista[array_rand($lista)];
                    }
                    $linhas[] = [
                        'processo_id' => $processoId, 'numero_item' => $k, 'item_catalogo_id' => $item['id'],
                        'codigo_material_sufixo' => $item['codigo'].'-'.$item['unidade_id'], 'descricao' => $item['descricao'],
                        'unidade_medida_id' => $item['unidade_id'], 'subelemento_despesa_id' => $item['subelemento'],
                        'quantidade_estimada' => $quant, 'quantidade_adjudicada' => $comResultado ? $quant : null,
                        'valor_unitario_estimado' => $unitEst, 'valor_unitario_adjudicado' => $unitAdj,
                        'valor_total_estimado' => $totEst, 'valor_total_adjudicado' => $totAdj, 'valor_total_homologado' => $totAdj,
                        'valor_total_contratado' => $contratado, 'fornecedor_id' => $comResultado ? $vencedor : null,
                        'beneficio_mpe' => in_array($codigoSist, ['PREGAO', 'DISPENSA'], true)
                            ? ($totEst <= 80000 && mt_rand(1, 100) <= 55 ? 'EXCLUSIVO' : ($totEst > 500000 && mt_rand(1, 100) <= 25 ? 'COTA_RESERVADA' : 'NENHUM'))
                            : 'NENHUM',
                        'data_adjudicacao' => $comResultado ? $data->addDays(mt_rand(5, 30))->min($hoje) : null,
                        'situacao' => $comResultado ? ($contratado !== null ? 'CONTRATADO' : 'HOMOLOGADO') : ($itemDeserto ? 'DESERTO' : ($situacao === 'EM_ANDAMENTO' ? 'EM_ANDAMENTO' : $situacao)),
                    ] + $this->base();
                    $somas['est'] += $totEst;
                    $somas['adj'] += $totAdj ?? 0;
                    $somas['hom'] += $totAdj ?? 0;
                    $somas['con'] += $contratado ?? 0;
                }
                DB::table('processos_itens')->insert($linhas);
                $temContrato = false;
                DB::table('processos')->where('id', $processoId)->update([
                    'valor_estimado' => $somas['est'],
                    'valor_adjudicado' => $temResultado ? $somas['adj'] : null,
                    'valor_homologado' => $temResultado ? $somas['hom'] : null,
                    'valor_contratado' => $temResultado && $somas['con'] > 0 ? $somas['con'] : null,
                ]);

                $this->participantes($processoId, $situacao, (bool) $s->com_disputa, $codigoSist === 'CHAMADA_PNAE' ? $fornecedores['paf'] : $fornecedores['geral']);

                if (! $temResultado) {
                    $this->fases($processoId, $codigoSist, $situacao, $data, false, false);

                    continue;
                }

                $itensGravados = DB::table('processos_itens')->where('processo_id', $processoId)->whereNotNull('fornecedor_id')->get();
                if ($rp) {
                    $seqAta++;
                    $inicio = $data->addDays(mt_rand(20, 45));
                    $ataId = DB::table('atas_registro_precos')->insertGetId([
                        'uid' => sprintf('DEMO-ATA-%05d', $seqAta), 'numero' => sprintf('%03d/%d', $seqAta, $inicio->year),
                        'numero_publicacao' => sprintf('%d/%05d', $ano, $seq), 'processo_id' => $processoId,
                        'orgao_gerenciador_id' => $u['id'], 'objeto' => 'ATA DE REGISTRO DE PREÇOS (DADO FICTÍCIO)',
                        'data_assinatura' => $inicio, 'vigencia_inicio' => $inicio, 'vigencia_fim' => $inicio->addYear(),
                        'situacao' => $inicio->addYear()->greaterThanOrEqualTo($hoje) ? 'VIGENTE' : 'EXPIRADA',
                        'valor_estimado' => $somas['est'], 'valor_registrado' => $somas['adj'],
                    ] + $this->base());
                    foreach ($itensGravados as $ig) {
                        $ataItemId = DB::table('atas_itens')->insertGetId([
                            'ata_id' => $ataId, 'processo_item_id' => $ig->id, 'item_catalogo_id' => $ig->item_catalogo_id,
                            'fornecedor_id' => $ig->fornecedor_id, 'quantidade_registrada' => $ig->quantidade_adjudicada,
                            'valor_unitario_estimado' => $ig->valor_unitario_estimado, 'valor_unitario_registrado' => $ig->valor_unitario_adjudicado,
                            'created_at' => $this->agora, 'updated_at' => $this->agora,
                        ]);
                        $this->usoDaAta($ataItemId, $ataId, $u['id'], $unidades, (float) $ig->quantidade_adjudicada, (float) $ig->valor_unitario_adjudicado, $inicio, $hoje);
                    }
                }

                if ($codigoSist !== 'CHAMADA_PNAE' && $itensGravados->isNotEmpty() && mt_rand(1, 100) <= 45) {
                    $seqContrato++;
                    $inicio = $data->addDays(mt_rand(30, 60));
                    $fim = $inicio->addMonths(mt_rand(6, 30));
                    $valor = (float) $itensGravados->sum('valor_total_contratado');
                    DB::table('contratos')->insert([
                        'uid' => sprintf('DEMO-CT-%05d', $seqContrato), 'numero' => sprintf('%03d/%d', $seqContrato, $inicio->year),
                        'processo_id' => $processoId, 'unidade_compradora_id' => $u['id'], 'fornecedor_id' => $itensGravados->first()->fornecedor_id,
                        'objeto' => 'CONTRATO (DADO FICTÍCIO)', 'valor_inicial' => $valor > 0 ? $valor : (float) $itensGravados->sum('valor_total_homologado'),
                        'data_assinatura' => $inicio, 'vigencia_inicio' => $inicio, 'vigencia_fim' => $fim,
                        'situacao' => $fim->greaterThanOrEqualTo($hoje) ? 'VIGENTE' : 'ENCERRADO',
                    ] + $this->base());
                    $temContrato = true;
                }
                $this->fases($processoId, $codigoSist, $situacao, $data, $somas['con'] > 0, $temContrato);
            }
        }
    }

    /**
     * Licitantes do processo: vencedores dos itens e, nos procedimentos com disputa, outros participantes
     * até a quantidade informada no processo.
     */
    private function participantes(int $processoId, string $situacao, bool $comDisputa, array $lista): void
    {
        if (in_array($situacao, ['DESERTO', 'REVOGADO', 'EM_ANDAMENTO'], true)) {
            DB::table('processos')->where('id', $processoId)->update(['quantidade_participantes' => $situacao === 'DESERTO' ? 0 : null]);

            return;
        }
        $vencedores = DB::table('processos_itens')->where('processo_id', $processoId)->whereNotNull('fornecedor_id')
            ->groupBy('fornecedor_id')->selectRaw('fornecedor_id, SUM(valor_total_adjudicado) AS total')->pluck('total', 'fornecedor_id')->all();
        $alvo = (int) DB::table('processos')->where('id', $processoId)->value('quantidade_participantes');
        $alvo = $comDisputa ? max($alvo, count($vencedores)) : max(1, count($vencedores));

        $linhas = [];
        foreach ($vencedores as $id => $total) {
            $linhas[$id] = ['processo_id' => $processoId, 'fornecedor_id' => $id, 'vencedor' => true, 'habilitado' => true,
                'valor_proposta' => round((float) $total, 2)] + $this->base();
        }
        $tentativas = 0;
        while (count($linhas) < $alvo && $tentativas++ < 50) {
            $id = $lista[array_rand($lista)];
            if (! isset($linhas[$id])) {
                $linhas[$id] = ['processo_id' => $processoId, 'fornecedor_id' => $id, 'vencedor' => false,
                    'habilitado' => mt_rand(1, 100) <= 85, 'valor_proposta' => null] + $this->base();
            }
        }
        if ($linhas) {
            DB::table('processos_participantes')->insert(array_values($linhas));
        }
        DB::table('processos')->where('id', $processoId)->update(['quantidade_participantes' => count($linhas)]);
    }

    /** Fases concluídas do processo, conforme o rito do procedimento e a situação. */
    private function fases(int $processoId, string $sistematica, string $situacao, CarbonImmutable $abertura, bool $contratado, bool $temContrato): void
    {
        $rito = FasesProcesso::doProcedimento($sistematica);
        $ate = match ($situacao) {
            'CONCLUIDO' => $temContrato ? 'EXECUCAO' : ($contratado ? 'CONTRATACAO' : 'HOMOLOGACAO'),
            'DESERTO' => in_array('PROPOSTAS', $rito, true) ? 'PROPOSTAS' : 'DIVULGACAO',
            'FRACASSADO' => in_array('HABILITACAO', $rito, true) ? 'HABILITACAO' : 'JULGAMENTO',
            'REVOGADO', 'ANULADO' => 'JULGAMENTO',
            default => $rito[mt_rand(1, max(1, count($rito) - 4))],
        };
        $limite = array_search($ate, $rito, true);
        $limite = $limite === false ? count($rito) - 1 : $limite;

        $inicio = $abertura->subDays(mt_rand(30, 90));
        $linhas = [];
        foreach (array_slice($rito, 0, $limite + 1) as $i => $fase) {
            $duracao = match ($fase) {
                'PLANEJAMENTO' => mt_rand(10, 40), 'PREPARATORIA' => mt_rand(10, 30), 'DIVULGACAO' => mt_rand(8, 15),
                'EXECUCAO' => mt_rand(180, 360), default => mt_rand(1, 10),
            };
            $referencia = CarbonImmutable::parse('2026-10-30');
            if ($inicio->greaterThan($referencia)) {
                break; // fases futuras ainda não começaram
            }
            $fim = $inicio->addDays($duracao);
            $emCurso = ($situacao === 'EM_ANDAMENTO' && $i === $limite) || $fim->greaterThan($referencia);
            $linhas[] = ['processo_id' => $processoId, 'fase' => $fase, 'data_inicio' => $inicio,
                'data_fim' => $emCurso ? null : $fim, 'observacao' => null,
                'created_at' => $this->agora, 'updated_at' => $this->agora];
            $inicio = $inicio->addDays($duracao);
        }
        DB::table('processos_fases')->insert($linhas);
        $dados = ['fase_atual' => $ate];
        if ($situacao === 'CONCLUIDO') {
            $adj = DB::table('processos_itens')->where('processo_id', $processoId)->selectRaw('MIN(data_adjudicacao) AS ini, MAX(data_adjudicacao) AS fim')->first();
            if ($adj?->ini) {
                $dados['data_adjudicacao'] = $adj->ini;
                $dados['data_homologacao'] = CarbonImmutable::parse($adj->fim)->addDays(mt_rand(1, 10))->min(CarbonImmutable::parse('2026-10-30'))->toDateString();
            }
        }
        DB::table('processos')->where('id', $processoId)->update($dados);
    }

    /** Categoria de alimento (seção 13) a partir da descrição do item demonstrativo. */
    private static function categoriaAlimento(string $descricao): array
    {
        $mapa = [
            'BANANA' => ['FRUTAS', true], 'LARANJA' => ['FRUTAS', true], 'TOMATE' => ['LEGUMES', true], 'ALFACE' => ['VERDURAS', true],
            'ABÓBORA' => ['LEGUMES', true], 'CENOURA' => ['LEGUMES', true], 'CARNE' => ['CARNES', true], 'FRANGO' => ['AVES', true],
            'PEIXE' => ['PEIXES', true], 'ARROZ' => ['CEREAIS', false], 'FEIJÃO' => ['GRAOS', false],
            'FARINHA DE MANDIOCA' => ['INDUSTRIALIZADOS', false], 'LEITE' => ['LATICINIOS', false], 'QUEIJO' => ['LATICINIOS', true],
        ];
        foreach ($mapa as $chave => [$categoria, $perecivel]) {
            if (str_starts_with($descricao, $chave)) {
                return ['categoria_alimento' => $categoria, 'perecivel' => $perecivel];
            }
        }

        return [];
    }

    /**
     * Participantes e utilizações de um item de ata: o gerenciador e até 3 participantes com quantidade prevista,
     * utilizações ao longo da vigência e, às vezes, adesões (caronas). Alguns itens passam de 100% de uso,
     * para demonstrar o sinalizador de análise.
     */
    private function usoDaAta(int $ataItemId, int $ataId, int $gerenciador, array $unidades, float $quantidade, float $unitario, CarbonImmutable $inicio, CarbonImmutable $hoje): void
    {
        if ($quantidade <= 0 || $unitario <= 0) {
            return;
        }
        $outras = array_values(array_filter($unidades, fn ($x) => $x['id'] !== $gerenciador));
        $participantes = [[$gerenciador, 'GERENCIADOR']];
        foreach ((array) array_rand($outras, mt_rand(1, 3)) as $k) {
            $participantes[] = [$outras[$k]['id'], 'PARTICIPANTE'];
        }
        $pesos = array_map(fn () => mt_rand(1, 10), $participantes);
        $somaPesos = array_sum($pesos);
        $perfilUso = mt_rand(1, 100); // define se o item terá uso baixo, médio, alto ou acima do registrado
        $fator = $perfilUso <= 20 ? mt_rand(0, 25) / 100 : ($perfilUso <= 70 ? mt_rand(30, 75) / 100 : ($perfilUso <= 94 ? mt_rand(80, 100) / 100 : mt_rand(105, 130) / 100));
        $fim = $inicio->addYear()->min($hoje);
        $dias = max(1, $inicio->diffInDays($fim));

        foreach ($participantes as $i => [$unidade, $tipo]) {
            $prevista = round($quantidade * $pesos[$i] / $somaPesos, 4);
            DB::table('atas_participantes')->insert([
                'ata_id' => $ataId, 'ata_item_id' => $ataItemId, 'unidade_compradora_id' => $unidade, 'tipo' => $tipo,
                'quantidade_prevista' => $prevista, 'created_at' => $this->agora, 'updated_at' => $this->agora,
            ]);
            $this->utilizar($ataItemId, $unidade, $prevista * $fator, $unitario, $inicio, $dias);
        }
        // Adesão (carona) em parte das atas
        if (mt_rand(1, 100) <= 15 && $outras) {
            $aderente = $outras[array_rand($outras)]['id'];
            $data = $inicio->addDays(mt_rand(0, (int) $dias));
            DB::table('atas_participantes')->insert([
                'ata_id' => $ataId, 'ata_item_id' => $ataItemId, 'unidade_compradora_id' => $aderente, 'tipo' => 'ADERENTE',
                'quantidade_prevista' => null, 'data_adesao' => $data, 'created_at' => $this->agora, 'updated_at' => $this->agora,
            ]);
            $this->utilizar($ataItemId, $aderente, $quantidade * mt_rand(10, 40) / 100, $unitario, $data, 1);
        }
    }

    private function utilizar(int $ataItemId, int $unidade, float $total, float $unitario, CarbonImmutable $inicio, float $dias): void
    {
        $partes = mt_rand(1, 3);
        for ($k = 0; $k < $partes; $k++) {
            $q = round($total / $partes, 4);
            if ($q <= 0) {
                continue;
            }
            DB::table('atas_utilizacoes')->insert([
                'ata_item_id' => $ataItemId, 'unidade_compradora_id' => $unidade, 'quantidade' => $q, 'valor_total' => round($q * $unitario, 2),
                'data' => $inicio->addDays(mt_rand(0, (int) $dias)),
            ] + $this->base());
        }
    }
}
