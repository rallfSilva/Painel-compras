<?php

namespace App\Services\Compras;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Services\Indicadores\DashboardService;
use App\Services\Indicadores\IndicadorService;
use App\Services\Indicadores\OpcoesCalculo;
use Illuminate\Support\Facades\DB;

/**
 * Módulo Órgãos e Unidades Compradoras (seção 5, item 15): hierarquia
 * Unidade superior > Órgão > Unidade compradora, com totais, e ficha da unidade.
 */
class OrgaosService
{
    public function __construct(
        private readonly FiltroService $filtros,
        private readonly IndicadorService $indicadores,
        private readonly DashboardService $dashboard,
        private readonly ComprasTotaisService $compras,
    ) {}

    /** Hierarquia com valor contratado, processos e fornecedores em cada nível. */
    public function arvore(FiltrosAplicados $f): array
    {
        $linhas = $this->filtros->baseItens($f)
            ->leftJoin('unidades_superiores as us', 'us.id', '=', 'o.unidade_superior_id')
            ->groupBy('uc.id')
            ->selectRaw('uc.id, MAX(uc.nome) AS nome, MAX(uc.uasg) AS uasg, MAX(mc.nome) AS municipio, MAX(o.id) AS orgao_id,
                MAX(o.nome) AS orgao, MAX(o.sigla) AS orgao_sigla, MAX(o.tipo) AS orgao_tipo, MAX(us.id) AS us_id, MAX(us.nome) AS us_nome,
                SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT p.id) AS processos, COUNT(DISTINCT pi.fornecedor_id) AS fornecedores')
            ->get();
        $total = (float) $linhas->sum('contratado');
        $pct = fn ($v) => $total > 0 ? round($v / $total * 100, 2) : null;

        // Fornecedores distintos por órgão e unidade superior (não é a soma das unidades)
        $fornOrgao = $this->filtros->baseItens($f)->groupBy('o.id')->selectRaw('o.id, COUNT(DISTINCT pi.fornecedor_id) AS n')->pluck('n', 'id');
        $fornUs = $this->filtros->baseItens($f)->groupBy('o.unidade_superior_id')
            ->selectRaw('o.unidade_superior_id AS id, COUNT(DISTINCT pi.fornecedor_id) AS n')->pluck('n', 'id');
        $procOrgao = $this->filtros->baseItens($f)->groupBy('o.id')->selectRaw('o.id, COUNT(DISTINCT p.id) AS n')->pluck('n', 'id');

        return $linhas->groupBy(fn ($l) => $l->us_id ?? 0)->map(function ($doUs, $usId) use ($pct, $fornOrgao, $fornUs, $procOrgao) {
            $orgaos = $doUs->groupBy('orgao_id')->map(function ($doOrgao, $oid) use ($pct, $fornOrgao, $procOrgao) {
                $c = (float) $doOrgao->sum('contratado');
                $primeiro = $doOrgao->first();

                return [
                    'id' => (int) $oid, 'chave' => "o:$oid", 'nome' => $primeiro->orgao, 'sigla' => $primeiro->orgao_sigla, 'tipo' => $primeiro->orgao_tipo,
                    'contratado' => round($c, 2), 'percentual' => $pct($c), 'processos' => (int) ($procOrgao[$oid] ?? 0),
                    'fornecedores' => (int) ($fornOrgao[$oid] ?? 0),
                    'unidades' => $doOrgao->sortByDesc('contratado')->map(fn ($u) => [
                        'id' => (int) $u->id, 'chave' => "u:$u->id", 'nome' => $u->nome, 'uasg' => $u->uasg, 'municipio' => $u->municipio,
                        'contratado' => round((float) $u->contratado, 2), 'percentual' => $pct((float) $u->contratado),
                        'processos' => (int) $u->processos, 'fornecedores' => (int) $u->fornecedores,
                    ])->values()->all(),
                ];
            })->sortByDesc('contratado')->values();
            $c = (float) $doUs->sum('contratado');

            return [
                'id' => $usId ?: null, 'chave' => $usId ? "us:$usId" : null, 'nome' => $doUs->first()->us_nome ?? 'Sem unidade superior',
                'contratado' => round($c, 2), 'percentual' => $pct($c), 'processos' => (int) $orgaos->sum('processos'),
                'fornecedores' => (int) ($fornUs[$usId ?: ''] ?? 0), 'orgaos' => $orgaos->all(),
            ];
        })->sortByDesc('contratado')->values()->all();
    }

    /** Ficha da unidade compradora: cadastro, indicadores e principais compras, dentro dos filtros globais. */
    public function unidade(int $id, FiltrosAplicados $f, OpcoesCalculo $o): ?array
    {
        $u = DB::table('unidades_compradoras as uc')
            ->join('orgaos as o', 'o.id', '=', 'uc.orgao_id')
            ->leftJoin('unidades_superiores as us', 'us.id', '=', 'o.unidade_superior_id')
            ->leftJoin('municipios as m', 'm.id', '=', 'uc.municipio_id')
            ->leftJoin('regioes_planejamento as rp', 'rp.id', '=', 'm.regiao_planejamento_id')
            ->where('uc.id', $id)
            ->when($f->orgaosPermitidos !== null, fn ($q) => $q->whereIn('o.id', $f->orgaosPermitidos ?: [0]))
            ->select(['uc.id', 'uc.nome', 'uc.codigo', 'uc.uasg', 'uc.ativo', 'uc.ficticio', 'o.id as orgao_id', 'o.nome as orgao', 'o.sigla as orgao_sigla',
                'o.cnpj as orgao_cnpj', 'o.tipo as orgao_tipo', 'us.nome as unidade_superior', 'm.nome as municipio', 'rp.nome as regiao'])
            ->first();
        if (! $u) {
            return null;
        }

        $fu = $f->com('unidade', ["u:$id"]);
        $ind = $this->indicadores->calcular($fu, $o);

        return [
            'unidade' => $u,
            'indicadores' => collect($ind)->only(['total_estimado', 'total_contratado', 'economia', 'percentual_economia', 'processos',
                'itens_comprados', 'fornecedores', 'qtd_atas', 'contratos_vigentes'])->all(),
            'evolucao_anual' => $this->dashboard->evolucaoAnual($fu, $o),
            'por_modalidade' => $this->compras->agrupar($fu, 's.id', 's.nome', 'sistematica', 's', 8),
            'por_fornecedor' => $this->compras->agrupar($fu, 'fo.id', 'fo.razao_social', 'fornecedor', 'fo', 10),
            'por_grupo_material' => $this->compras->agrupar($fu, 'cm.grupo_id', 'gm.nome', 'classe', 'g', 8,
                fn ($q) => $q->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')),
        ];
    }
}
