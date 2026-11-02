<?php

namespace App\Services\Inteligencia;

use App\Services\Compras\ComprasTotaisService;
use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

/**
 * MÓDULO ANÁLISE DO TOTAL COMPRADO (seção 19).
 *
 * Árvore de decomposição: a agregação é feita no backend, um nível por vez. O caminho já aberto
 * (pares dimensão:valor) vira condição da consulta, sem substituir os filtros globais.
 */
class AnaliseTotalService
{
    /** dimensão => [coluna id, coluna nome, filtro global, prefixo da chave, rótulo] */
    public const DIMENSOES = [
        'ano' => ['p.ano', 'p.ano', 'tempo', 'a', 'Ano'],
        'unidade_superior' => ['o.unidade_superior_id', 'COALESCE(usup.sigla, usup.nome)', 'unidade', 'us', 'Unidade superior'],
        'unidade' => ['p.unidade_compradora_id', 'uc.nome', 'unidade', 'u', 'Unidade contratante'],
        'grupo' => ['cm.grupo_id', 'gm.nome', 'classe', 'g', 'Grupo'],
        'classe' => ['ic.classe_id', 'cm.nome', 'classe', 'c', 'Classe'],
        'sistematica' => ['p.sistematica_id', 's.nome', 'sistematica', 's', 'Sistemática'],
        'forma' => ['p.forma_aquisicao_id', 'fa.nome', 'sistematica', 'f', 'Forma de aquisição'],
        'amparo' => ['p.amparo_legal_id', 'al.descricao', 'amparo', 'a', 'Amparo legal'],
        'porte' => ['fo.porte_id', 'pe.nome', 'porte', 'p', 'Porte do fornecedor'],
        'uf_fornecedor' => ['ef.uf', 'ef.uf', 'fornecedor_local', 'uf', 'UF do fornecedor'],
        'municipio' => ['uc.municipio_id', 'mc.nome', 'comprador_local', 'm', 'Município comprador'],
    ];

    public const SEQUENCIA_PADRAO = ['ano', 'unidade', 'grupo', 'classe', 'sistematica', 'forma'];

    public const LIMITES = ['orgaos' => 10, 'fornecedores' => 20, 'itens' => 50, 'classes' => 20, 'municipios' => 10, 'processos' => 10, 'amparos' => 10];

    public function __construct(
        private readonly FiltroService $filtros,
        private readonly ComprasTotaisService $compras,
        private readonly Configuracoes $config,
    ) {}

    /** Junções das dimensões que não estão na base de itens. */
    public static function juntar(Builder $q): Builder
    {
        return $q->leftJoin('unidades_superiores as usup', 'usup.id', '=', 'o.unidade_superior_id')
            ->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')
            ->leftJoin('formas_aquisicao as fa', 'fa.id', '=', 'p.forma_aquisicao_id')
            ->leftJoin('amparos_legais as al', 'al.id', '=', 'p.amparo_legal_id')
            ->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id');
    }

    public function dimensoes(): array
    {
        return [
            'dimensoes' => collect(self::DIMENSOES)->map(fn ($d, $k) => ['codigo' => $k, 'nome' => $d[4]])->values()->all(),
            'sequencia_padrao' => self::SEQUENCIA_PADRAO,
        ];
    }

    /**
     * Lê o caminho "dimensao:valor,dimensao:valor" (valor "nulo" = não informado).
     *
     * @return list<array{dimensao: string, valor: ?string}>
     */
    public static function lerCaminho(string $texto): array
    {
        $caminho = [];
        foreach (array_filter(explode(',', $texto)) as $passo) {
            [$dim, $valor] = array_pad(explode(':', $passo, 2), 2, '');
            if (! isset(self::DIMENSOES[$dim])) {
                throw ValidationException::withMessages(['caminho' => "Dimensão desconhecida: $dim"]);
            }
            $caminho[] = ['dimensao' => $dim, 'valor' => $valor === '' || $valor === 'nulo' ? null : $valor];
        }

        return $caminho;
    }

    /**
     * Aplica o caminho como condições exatas (E), sem mexer nos filtros globais. A consulta precisa das
     * junções de juntar().
     *
     * @param  list<array{dimensao: string, valor: ?string}>  $caminho
     */
    public static function aplicarCaminho(Builder $q, array $caminho): Builder
    {
        foreach ($caminho as $passo) {
            $col = self::DIMENSOES[$passo['dimensao']][0];
            $passo['valor'] === null ? $q->whereNull($col) : $q->where($col, $passo['valor']);
        }

        return $q;
    }

    /**
     * @param  list<array{dimensao: string, valor: ?string}>  $caminho
     */
    public function decomposicao(FiltrosAplicados $f, array $caminho, string $proxima, int $limite = 15): array
    {
        if (! isset(self::DIMENSOES[$proxima])) {
            throw ValidationException::withMessages(['dimensao' => "Dimensão desconhecida: $proxima"]);
        }
        if (in_array($proxima, array_column($caminho, 'dimensao'), true)) {
            throw ValidationException::withMessages(['dimensao' => 'Essa dimensão já foi usada no caminho.']);
        }

        $q = self::aplicarCaminho(self::juntar($this->filtros->baseItens($f))->whereNotNull('pi.valor_total_contratado'), $caminho);

        [$col, $nome, $filtro, $prefixo, $rotulo] = self::DIMENSOES[$proxima];
        $linhas = $q->groupBy($col)
            ->selectRaw("$col AS valor, MAX($nome) AS nome, SUM(pi.valor_total_contratado) AS contratado,
                COUNT(DISTINCT p.id) AS processos, COUNT(*) AS itens")
            ->orderByDesc('contratado')->get();
        $total = (float) $linhas->sum('contratado');

        $nos = $linhas->take($limite)->map(fn ($r) => [
            'valor' => $r->valor !== null ? (string) $r->valor : null,
            'nome' => $r->nome !== null ? (string) $r->nome : 'Não informado',
            'contratado' => Sql::valor($r->contratado), 'percentual' => Sql::pct($r->contratado, $total),
            'processos' => (int) $r->processos, 'itens' => (int) $r->itens,
            'filtro' => $r->valor !== null ? $filtro : null, 'chave' => $r->valor !== null ? "$prefixo:$r->valor" : null,
        ])->values()->all();
        $resto = $linhas->slice($limite);
        if ($resto->isNotEmpty()) {
            $nos[] = ['valor' => null, 'nome' => sprintf('Demais (%d)', $resto->count()), 'contratado' => Sql::valor($resto->sum('contratado')),
                'percentual' => Sql::pct($resto->sum('contratado'), $total), 'processos' => (int) $resto->sum('processos'),
                'itens' => (int) $resto->sum('itens'), 'filtro' => null, 'chave' => null, 'agrupado' => true];
        }

        return ['dimensao' => $proxima, 'nome_dimensao' => $rotulo, 'total' => Sql::valor($total), 'nos' => $nos];
    }

    /** Tabela Unidade Superior × Unidade Contratante (19.2). */
    public function unidades(FiltrosAplicados $f): array
    {
        $linhas = self::juntar($this->filtros->baseItens($f))->whereNotNull('pi.valor_total_contratado')
            ->groupBy('o.unidade_superior_id', 'uc.id')
            ->selectRaw('o.unidade_superior_id AS us_id, MAX(COALESCE(usup.sigla, usup.nome)) AS us, MAX(usup.nome) AS us_nome,
                uc.id AS uc_id, MAX(uc.nome) AS uc, MAX(o.sigla) AS orgao, SUM(pi.valor_total_contratado) AS contratado,
                COUNT(DISTINCT p.id) AS processos, COUNT(DISTINCT pi.fornecedor_id) AS fornecedores')
            ->get();
        $total = (float) $linhas->sum('contratado');

        return $linhas->groupBy(fn ($r) => (string) $r->us_id)->map(function ($g) use ($total) {
            $sub = (float) $g->sum('contratado');
            $p = $g->first();

            return [
                'id' => $p->us_id !== null ? (int) $p->us_id : null, 'sigla' => $p->us ?? 'Sem unidade superior', 'nome' => $p->us_nome,
                'contratado' => Sql::valor($sub), 'percentual' => Sql::pct($sub, $total), 'processos' => (int) $g->sum('processos'),
                'chave' => $p->us_id !== null ? "us:$p->us_id" : null,
                'unidades' => $g->sortByDesc('contratado')->map(fn ($r) => [
                    'id' => (int) $r->uc_id, 'nome' => $r->uc, 'orgao' => $r->orgao, 'contratado' => Sql::valor($r->contratado),
                    'percentual_superior' => Sql::pct($r->contratado, $sub), 'percentual' => Sql::pct($r->contratado, $total),
                    'processos' => (int) $r->processos, 'fornecedores' => (int) $r->fornecedores, 'chave' => "u:$r->uc_id",
                ])->values()->all(),
            ];
        })->sortByDesc('contratado')->values()->all();
    }

    /** Hierarquia Amparo legal > Classe, com nº de itens e total (19.3). */
    public function amparoClasse(FiltrosAplicados $f): array
    {
        $linhas = self::juntar($this->filtros->baseItens($f))->whereNotNull('pi.valor_total_contratado')
            ->groupBy('p.amparo_legal_id', 'ic.classe_id')
            ->selectRaw('p.amparo_legal_id AS amparo_id, MAX(al.descricao) AS amparo, ic.classe_id, MAX(cm.nome) AS classe,
                COUNT(*) AS itens, SUM(pi.valor_total_contratado) AS contratado')
            ->get();
        $total = (float) $linhas->sum('contratado');

        return $linhas->groupBy(fn ($r) => (string) $r->amparo_id)->map(function ($g) use ($total) {
            $sub = (float) $g->sum('contratado');
            $p = $g->first();

            return [
                'id' => $p->amparo_id !== null ? (int) $p->amparo_id : null, 'nome' => $p->amparo ?? 'Não informado',
                'itens' => (int) $g->sum('itens'), 'contratado' => Sql::valor($sub), 'percentual' => Sql::pct($sub, $total),
                'chave' => $p->amparo_id !== null ? "a:$p->amparo_id" : null,
                'classes' => $g->sortByDesc('contratado')->map(fn ($r) => [
                    'id' => $r->classe_id !== null ? (int) $r->classe_id : null, 'nome' => $r->classe ?? 'Sem classe',
                    'itens' => (int) $r->itens, 'contratado' => Sql::valor($r->contratado), 'percentual_amparo' => Sql::pct($r->contratado, $sub),
                    'chave' => $r->classe_id !== null ? "c:$r->classe_id" : null,
                ])->values()->all(),
            ];
        })->sortByDesc('contratado')->values()->all();
    }

    /**
     * Rankings com limite configurável (19.4).
     *
     * @param  array<string, int>  $limites
     */
    public function rankings(FiltrosAplicados $f, array $limites): array
    {
        $l = fn (string $k) => max(1, min(200, (int) ($limites[$k] ?? self::LIMITES[$k])));
        $j = fn (Builder $q) => self::juntar($q);

        return [
            'limites' => collect(self::LIMITES)->map(fn ($v, $k) => $l($k))->all(),
            'orgaos' => $this->compras->agrupar($f, 'o.id', 'COALESCE(o.sigla, o.nome)', 'unidade', 'o', $l('orgaos')),
            'fornecedores' => $this->compras->agrupar($f, 'fo.id', 'fo.razao_social', 'fornecedor', 'fo', $l('fornecedores')),
            'itens' => $this->compras->agrupar($f, 'pi.item_catalogo_id', 'COALESCE(ic.nome_resumido, ic.descricao, pi.descricao)', 'item', 'i', $l('itens')),
            'classes' => $this->compras->agrupar($f, 'ic.classe_id', 'cm.nome', 'classe', 'c', $l('classes')),
            'municipios' => $this->compras->agrupar($f, 'uc.municipio_id', 'mc.nome', 'comprador_local', 'm', $l('municipios')),
            'amparos' => $this->compras->agrupar($f, 'p.amparo_legal_id', 'al.descricao', 'amparo', 'a', $l('amparos'), $j),
            'processos' => $this->processos($f, $l('processos')),
        ];
    }

    private function processos(FiltrosAplicados $f, int $limite): array
    {
        $total = (float) $this->filtros->baseItens($f)->sum('pi.valor_total_contratado');

        return $this->filtros->baseItens($f)->whereNotNull('pi.valor_total_contratado')
            ->groupBy('p.id')
            ->selectRaw('p.id, MAX(p.numero_publicacao) AS numero, MAX(p.objeto) AS objeto, MAX(o.sigla) AS orgao, SUM(pi.valor_total_contratado) AS contratado')
            ->orderByDesc('contratado')->limit($limite)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'nome' => trim(($r->numero ?? '').' · '.($r->orgao ?? ''), ' ·'), 'objeto' => $r->objeto,
                'contratado' => Sql::valor($r->contratado), 'percentual' => Sql::pct($r->contratado, $total)])
            ->all();
    }

    /**
     * Compras recorrentes: mesmo item e unidade de fornecimento em vários processos. Sinaliza possível
     * oportunidade de planejamento conjunto ou registro de preços; não é conclusão sobre fracionamento.
     */
    public function recorrentes(FiltrosAplicados $f, int $limite = 30): array
    {
        $minimo = max(2, $this->config->int('compras.recorrencia_minima', 3));
        $linhas = $this->filtros->baseItens($f)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->whereNotNull('pi.item_catalogo_id')->whereRaw(Sql::resultado())
            ->groupBy('pi.item_catalogo_id', 'pi.unidade_medida_id')
            ->havingRaw('COUNT(DISTINCT p.id) >= ?', [$minimo])
            ->selectRaw('pi.item_catalogo_id AS id, MAX(COALESCE(ic.nome_resumido, ic.descricao, pi.descricao)) AS nome, MAX(ic.codigo) AS codigo,
                MAX(um.sigla) AS unidade, COUNT(DISTINCT p.id) AS processos, COUNT(DISTINCT o.id) AS orgaos, COUNT(DISTINCT p.ano) AS anos,
                SUM(CASE WHEN p.registro_precos = 1 THEN 1 ELSE 0 END) AS em_rp, COUNT(*) AS compras,
                SUM(pi.valor_total_contratado) AS contratado, SUM(pi.quantidade_adjudicada) AS quantidade')
            ->orderByDesc('processos')->orderByDesc('contratado')->limit($limite)->get();

        return [
            'minimo_processos' => $minimo,
            'itens' => $linhas->map(fn ($r) => [
                'id' => (int) $r->id, 'nome' => $r->nome, 'codigo' => $r->codigo, 'unidade' => $r->unidade,
                'processos' => (int) $r->processos, 'orgaos' => (int) $r->orgaos, 'anos' => (int) $r->anos,
                'percentual_rp' => Sql::pct($r->em_rp, $r->compras), 'contratado' => Sql::valor($r->contratado),
                'quantidade' => $r->quantidade !== null ? round((float) $r->quantidade, 2) : null, 'chave' => "i:$r->id",
            ])->all(),
        ];
    }

    /**
     * Concentração de fornecedores: participação do maior, dos 5 maiores e índice HHI (0 a 10.000),
     * no total e por classe. Valores altos pedem análise; não indicam irregularidade por si.
     */
    public function concentracao(FiltrosAplicados $f): array
    {
        $limite = (float) $this->config->int('fornecedores.limite_concentracao', 30);
        $linhas = $this->filtros->baseItens($f)->whereNotNull('pi.fornecedor_id')->whereNotNull('pi.valor_total_contratado')
            ->groupBy('ic.classe_id', 'pi.fornecedor_id')
            ->selectRaw('ic.classe_id, MAX(cm.nome) AS classe, pi.fornecedor_id, MAX(fo.razao_social) AS fornecedor, SUM(pi.valor_total_contratado) AS valor')
            ->get();

        $indice = function ($valores) {
            $t = (float) $valores->sum();
            if ($t <= 0) {
                return ['hhi' => null, 'maior' => null, 'cinco' => null];
            }
            $ord = $valores->sortDesc()->values();

            return [
                'hhi' => (int) round($ord->sum(fn ($v) => (($v / $t) * 100) ** 2)),
                'maior' => round($ord[0] / $t * 100, 2),
                'cinco' => round($ord->take(5)->sum() / $t * 100, 2),
            ];
        };

        $porFornecedor = $linhas->groupBy('fornecedor_id')->map(fn ($g) => (float) $g->sum('valor'));
        $geral = $indice($porFornecedor);
        $topo = $porFornecedor->sortDesc()->keys()->first();

        $classes = $linhas->groupBy(fn ($r) => (string) $r->classe_id)->map(function ($g) use ($indice, $limite) {
            $i = $indice($g->pluck('valor')->map(fn ($v) => (float) $v));
            $maior = $g->sortByDesc('valor')->first();

            return $i + [
                'id' => $maior->classe_id !== null ? (int) $maior->classe_id : null, 'nome' => $maior->classe ?? 'Sem classe',
                'contratado' => Sql::valor($g->sum('valor')), 'fornecedores' => $g->count(),
                'maior_fornecedor' => $maior->fornecedor, 'maior_fornecedor_id' => (int) $maior->fornecedor_id,
                'sinalizada' => $i['maior'] !== null && $g->count() > 0 && $i['maior'] >= $limite,
            ];
        })->sortByDesc('contratado')->values()->take(30)->all();

        return [
            'limite' => $limite,
            'geral' => $geral + ['fornecedores' => $porFornecedor->count(),
                'maior_fornecedor' => $topo !== null ? $linhas->firstWhere('fornecedor_id', $topo)?->fornecedor : null],
            'classes' => $classes,
            'observacao' => 'HHI abaixo de 1.500 indica mercado pouco concentrado; entre 1.500 e 2.500, moderado; acima de 2.500, concentrado. A sinalização pede análise humana e não indica irregularidade.',
        ];
    }
}
