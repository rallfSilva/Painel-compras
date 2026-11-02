<?php

namespace App\Services\Compras;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Services\Indicadores\DashboardService;
use App\Services\Indicadores\OpcoesCalculo;
use Illuminate\Database\Query\Builder;

/**
 * Módulo Compras Totais (seção 7): visão consolidada, 10 gráficos e ranking das unidades compradoras.
 * Todos os agrupamentos usam o valor contratado dos itens, salvo indicação em contrário.
 */
class ComprasTotaisService
{
    public function __construct(
        private readonly FiltroService $filtros,
        private readonly DashboardService $dashboard,
    ) {}

    public function graficos(FiltrosAplicados $f, OpcoesCalculo $o): array
    {
        return [
            'evolucao_anual' => $this->dashboard->evolucaoAnual($f, $o),
            'por_orgao' => $this->agrupar($f, 'o.id', 'COALESCE(o.sigla, o.nome)', 'unidade', 'o', 12),
            'por_modalidade' => $this->agrupar($f, 's.id', 's.nome', 'sistematica', 's', 20),
            'por_natureza' => $this->agrupar($f, 'cn.id', 'cn.nome', 'natureza', 'n', 12,
                fn (Builder $q) => $q->leftJoin('classificacoes_natureza as cn', 'cn.id', '=', 'p.classificacao_natureza_id')),
            'por_municipio' => $this->agrupar($f, 'mc.id', 'mc.nome', 'comprador_local', 'm', 15),
            'por_fornecedor' => $this->agrupar($f, 'fo.id', 'fo.razao_social', 'fornecedor', 'fo', 12),
            'por_grupo_material' => $this->agrupar($f, 'gm.id', 'gm.nome', 'classe', 'g', 12,
                fn (Builder $q) => $q->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')),
            'por_grupo_porte' => $this->dashboard->porGrupoPorte($f),
            'com_sem_disputa' => $this->comSemDisputa($f),
            'top_unidades' => $this->dashboard->topUnidades($f, 20),
        ];
    }

    /**
     * Soma do valor contratado por uma dimensão, com os maiores e um grupo "Demais".
     * Cada linha traz o filtro global equivalente (filtro + chave) para o filtro cruzado na interface.
     */
    public function agrupar(FiltrosAplicados $f, string $colId, string $colNome, string $filtro, string $prefixo, int $limite, ?\Closure $join = null): array
    {
        $q = $this->filtros->baseItens($f);
        if ($join) {
            $join($q);
        }
        $linhas = $q->whereNotNull('pi.valor_total_contratado')
            ->groupBy($colId)
            ->selectRaw("$colId AS id, MAX($colNome) AS nome, SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT p.id) AS processos")
            ->orderByDesc('contratado')
            ->get();

        $total = (float) $linhas->sum('contratado');
        $map = fn ($r) => [
            'id' => $r->id !== null ? (int) $r->id : null,
            'nome' => $r->nome ?? 'Não informado',
            'contratado' => round((float) $r->contratado, 2),
            'processos' => (int) $r->processos,
            'percentual' => $total > 0 ? round($r->contratado / $total * 100, 2) : null,
            'filtro' => $r->id !== null ? $filtro : null,
            'chave' => $r->id !== null ? "$prefixo:$r->id" : null,
        ];

        $principais = $linhas->take($limite)->map($map)->values()->all();
        $resto = $linhas->slice($limite);
        if ($resto->isNotEmpty()) {
            $soma = (float) $resto->sum('contratado');
            $principais[] = [
                'id' => null, 'nome' => sprintf('Demais (%d)', $resto->count()), 'contratado' => round($soma, 2),
                'processos' => (int) $resto->sum('processos'), 'percentual' => $total > 0 ? round($soma / $total * 100, 2) : null,
                'filtro' => null, 'chave' => null, 'agrupado' => true,
            ];
        }

        return $principais;
    }

    /** Contratações com e sem disputa de preço: valor contratado e quantidade de processos. */
    public function comSemDisputa(FiltrosAplicados $f): array
    {
        $r = $this->filtros->baseItens($f)->selectRaw('
            SUM(CASE WHEN s.com_disputa = 1 THEN pi.valor_total_contratado END) AS valor_com,
            SUM(CASE WHEN s.com_disputa = 0 THEN pi.valor_total_contratado END) AS valor_sem,
            COUNT(DISTINCT CASE WHEN s.com_disputa = 1 THEN p.id END) AS proc_com,
            COUNT(DISTINCT CASE WHEN s.com_disputa = 0 THEN p.id END) AS proc_sem
        ')->first();
        $vt = (float) $r->valor_com + (float) $r->valor_sem;
        $pt = (int) $r->proc_com + (int) $r->proc_sem;

        return collect([['COM', 'Com disputa', $r->valor_com, $r->proc_com], ['SEM', 'Sem disputa', $r->valor_sem, $r->proc_sem]])
            ->map(fn ($l) => [
                'grupo' => $l[0], 'nome' => $l[1], 'contratado' => round((float) $l[2], 2), 'processos' => (int) $l[3],
                'percentual_valor' => $vt > 0 ? round($l[2] / $vt * 100, 2) : null,
                'percentual_processos' => $pt > 0 ? round($l[3] / $pt * 100, 2) : null,
            ])->all();
    }
}
