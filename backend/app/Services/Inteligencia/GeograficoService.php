<?php

namespace App\Services\Inteligencia;

use App\Services\Compras\ComprasTotaisService;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Services\Indicadores\DashboardService;
use App\Services\Indicadores\OpcoesCalculo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * PAINEL POR ESTADO DA FEDERAÇÃO / REGIÃO DE PLANEJAMENTO (seção 9).
 * Comprador = município da unidade compradora. Fornecedor = município cadastrado do fornecedor.
 * Fornecedor local = fornecedor sediado em Roraima.
 */
class GeograficoService
{
    public function __construct(
        private readonly FiltroService $filtros,
        private readonly ComprasTotaisService $compras,
        private readonly DashboardService $dashboard,
    ) {}

    public function painel(FiltrosAplicados $f): array
    {
        return [
            'por_uf' => $this->porUf($f),
            'municipios' => $this->municipios($f),
            'por_regiao' => $this->compras->agrupar($f, 'mc.regiao_planejamento_id', 'rc.nome', 'comprador_local', 'r', 20,
                fn (Builder $q) => $q->leftJoin('regioes_planejamento as rc', 'rc.id', '=', 'mc.regiao_planejamento_id')),
            'fornecedores_por_regiao' => $this->fornecedoresPorRegiao($f),
            'local_outros' => $this->localOutros($f),
        ];
    }

    /** Valor contratado e fornecedores por UF do fornecedor (mapa do Brasil). */
    private function porUf(FiltrosAplicados $f): array
    {
        $linhas = $this->filtros->baseItens($f)->whereNotNull('pi.valor_total_contratado')
            ->groupBy('ef.uf')
            ->selectRaw('ef.uf, SUM(pi.valor_total_contratado) AS valor, COUNT(DISTINCT pi.fornecedor_id) AS fornecedores, COUNT(DISTINCT p.id) AS processos')
            ->orderByDesc('valor')->get();
        $total = (float) $linhas->sum('valor');

        return $linhas->map(fn ($r) => [
            'uf' => $r->uf, 'nome' => $r->uf ?? 'Não informado', 'contratado' => Sql::valor($r->valor),
            'fornecedores' => (int) $r->fornecedores, 'processos' => (int) $r->processos, 'percentual' => Sql::pct($r->valor, $total),
            'filtro' => $r->uf ? 'fornecedor_local' : null, 'chave' => $r->uf ? "uf:$r->uf" : null,
        ])->all();
    }

    /**
     * Os 15 municípios de Roraima: compras dos compradores sediados no município e vendas dos fornecedores
     * sediados nele (participação econômica do município como fornecedor).
     */
    private function municipios(FiltrosAplicados $f): array
    {
        $compras = $this->filtros->baseItens($f)->groupBy('uc.municipio_id')
            ->selectRaw('uc.municipio_id AS id, SUM(pi.valor_total_contratado) AS valor, COUNT(DISTINCT p.id) AS processos, COUNT(DISTINCT uc.id) AS unidades,
                SUM(CASE WHEN fo.municipio_id = uc.municipio_id THEN pi.valor_total_contratado END) AS valor_local')
            ->get()->keyBy('id');
        $vendas = $this->filtros->baseItens($f)->whereNotNull('fo.municipio_id')->groupBy('fo.municipio_id')
            ->selectRaw('fo.municipio_id AS id, SUM(pi.valor_total_contratado) AS valor, COUNT(DISTINCT pi.fornecedor_id) AS fornecedores')
            ->get()->keyBy('id');
        $totalCompras = (float) $compras->sum('valor');
        $totalVendas = (float) $vendas->sum('valor');

        return DB::table('municipios as m')->join('estados as e', 'e.id', '=', 'm.estado_id')
            ->leftJoin('regioes_planejamento as r', 'r.id', '=', 'm.regiao_planejamento_id')
            ->where('e.uf', 'RR')->orderBy('m.nome')
            ->get(['m.id', 'm.codigo_ibge', 'm.nome', 'r.id as regiao_id', 'r.nome as regiao'])
            ->map(fn ($m) => [
                'id' => (int) $m->id, 'codigo_ibge' => $m->codigo_ibge, 'nome' => $m->nome,
                'regiao_id' => $m->regiao_id !== null ? (int) $m->regiao_id : null, 'regiao' => $m->regiao,
                'contratado' => Sql::valor($compras[$m->id]->valor ?? 0),
                'percentual' => Sql::pct($compras[$m->id]->valor ?? 0, $totalCompras),
                'processos' => (int) ($compras[$m->id]->processos ?? 0),
                'unidades' => (int) ($compras[$m->id]->unidades ?? 0),
                'percentual_fornecedores_mesmo_municipio' => Sql::pct($compras[$m->id]->valor_local ?? 0, $compras[$m->id]->valor ?? 0),
                'fornecedores' => (int) ($vendas[$m->id]->fornecedores ?? 0),
                'vendido' => Sql::valor($vendas[$m->id]->valor ?? 0),
                'participacao_economica' => Sql::pct($vendas[$m->id]->valor ?? 0, $totalVendas),
                'filtro' => 'comprador_local', 'chave' => "m:$m->id",
            ])->all();
    }

    private function fornecedoresPorRegiao(FiltrosAplicados $f): array
    {
        return $this->filtros->baseItens($f)
            ->join('regioes_planejamento as rf', 'rf.id', '=', 'mf.regiao_planejamento_id')
            ->groupBy('rf.id')
            ->selectRaw('rf.id, MAX(rf.nome) AS nome, COUNT(DISTINCT pi.fornecedor_id) AS fornecedores, SUM(pi.valor_total_contratado) AS valor')
            ->orderByDesc('fornecedores')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'nome' => $r->nome, 'fornecedores' => (int) $r->fornecedores, 'contratado' => Sql::valor($r->valor)])
            ->all();
    }

    /** Fornecedores de Roraima x de outros estados (valor contratado e quantidade). */
    private function localOutros(FiltrosAplicados $f): array
    {
        $r = $this->filtros->baseItens($f)->whereNotNull('pi.valor_total_contratado')
            ->selectRaw("SUM(CASE WHEN ef.uf = 'RR' THEN pi.valor_total_contratado END) AS rr,
                SUM(CASE WHEN ef.uf IS NOT NULL AND ef.uf <> 'RR' THEN pi.valor_total_contratado END) AS outros,
                SUM(CASE WHEN ef.uf IS NULL THEN pi.valor_total_contratado END) AS ni,
                COUNT(DISTINCT CASE WHEN ef.uf = 'RR' THEN pi.fornecedor_id END) AS f_rr,
                COUNT(DISTINCT CASE WHEN ef.uf IS NOT NULL AND ef.uf <> 'RR' THEN pi.fornecedor_id END) AS f_outros,
                COUNT(DISTINCT CASE WHEN ef.uf IS NULL THEN pi.fornecedor_id END) AS f_ni,
                SUM(pi.valor_total_contratado) AS total")->first();

        return [
            ['grupo' => 'RR', 'nome' => 'Fornecedores de Roraima', 'contratado' => Sql::valor($r->rr ?? 0), 'percentual' => Sql::pct($r->rr, $r->total), 'fornecedores' => (int) $r->f_rr],
            ['grupo' => 'OUTROS', 'nome' => 'Fornecedores de outros estados', 'contratado' => Sql::valor($r->outros ?? 0), 'percentual' => Sql::pct($r->outros, $r->total), 'fornecedores' => (int) $r->f_outros],
            ['grupo' => 'NI', 'nome' => 'Localização não informada', 'contratado' => Sql::valor($r->ni ?? 0), 'percentual' => Sql::pct($r->ni, $r->total), 'fornecedores' => (int) $r->f_ni],
        ];
    }

    /** Ficha do município (comprador): órgãos, fornecedores, materiais, modalidades e evolução. */
    public function municipio(int $id, FiltrosAplicados $f, OpcoesCalculo $o): ?array
    {
        $m = DB::table('municipios as m')->join('estados as e', 'e.id', '=', 'm.estado_id')
            ->leftJoin('regioes_planejamento as r', 'r.id', '=', 'm.regiao_planejamento_id')
            ->where('m.id', $id)->first(['m.id', 'm.nome', 'm.codigo_ibge', 'e.uf', 'r.nome as regiao']);
        if (! $m) {
            return null;
        }
        $g = $f->com('comprador_local', ["m:$id"]);
        $tot = $this->filtros->baseItens($g)->selectRaw('SUM(pi.valor_total_contratado) AS valor, COUNT(DISTINCT p.id) AS processos,
            COUNT(DISTINCT pi.fornecedor_id) AS fornecedores, COUNT(DISTINCT uc.id) AS unidades')->first();

        return [
            'municipio' => ['id' => (int) $m->id, 'nome' => $m->nome, 'codigo_ibge' => $m->codigo_ibge, 'uf' => $m->uf, 'regiao' => $m->regiao],
            'resumo' => ['contratado' => Sql::valor($tot->valor), 'processos' => (int) $tot->processos, 'fornecedores' => (int) $tot->fornecedores, 'unidades' => (int) $tot->unidades],
            'orgaos' => $this->compras->agrupar($g, 'o.id', 'COALESCE(o.sigla, o.nome)', 'unidade', 'o', 8),
            'fornecedores' => $this->compras->agrupar($g, 'fo.id', 'fo.razao_social', 'fornecedor', 'fo', 8),
            'materiais' => $this->compras->agrupar($g, 'cm.id', 'cm.nome', 'classe', 'c', 8),
            'modalidades' => $this->compras->agrupar($g, 's.id', 's.nome', 'sistematica', 's', 8),
            'evolucao' => $this->dashboard->evolucaoAnual($g, $o),
        ];
    }
}
