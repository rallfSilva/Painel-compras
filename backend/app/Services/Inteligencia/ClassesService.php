<?php

namespace App\Services\Inteligencia;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use Illuminate\Support\Facades\DB;

/**
 * MÓDULO COMPRAS POR CLASSE DE MATERIAL (seção 18).
 * Na classe, quantidades de unidades diferentes não são somadas: quantidade e valor unitário médio
 * aparecem por item e unidade de fornecimento, ao abrir a classe.
 */
class ClassesService
{
    public function __construct(
        private readonly FiltroService $filtros,
        private readonly NaturezaService $natureza,
    ) {}

    public function painel(FiltrosAplicados $f): array
    {
        $res = Sql::resultado();
        $linhas = $this->filtros->baseItens($f)
            ->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')
            ->whereRaw($res)
            ->groupBy('cm.id')
            ->selectRaw('cm.id, MAX(cm.codigo) AS codigo, MAX(cm.nome) AS classe, MAX(gm.id) AS grupo_id, MAX(gm.nome) AS grupo, MAX(gm.tipo) AS tipo,
                SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT pi.item_catalogo_id) AS itens, COUNT(*) AS linhas,
                COUNT(DISTINCT p.id) AS processos, COUNT(DISTINCT o.id) AS orgaos, COUNT(DISTINCT pi.fornecedor_id) AS fornecedores')
            ->orderByDesc('contratado')
            ->get();
        $total = (float) $linhas->sum('contratado');

        $classes = $linhas->map(fn ($r) => [
            'id' => $r->id !== null ? (int) $r->id : null, 'codigo' => $r->codigo, 'classe' => $r->classe ?? 'Sem classe',
            'grupo_id' => $r->grupo_id !== null ? (int) $r->grupo_id : null, 'grupo' => $r->grupo ?? 'Sem grupo',
            'tipo' => $r->tipo === 'S' ? 'Serviço' : ($r->tipo === 'M' ? 'Material' : null),
            'contratado' => Sql::valor($r->contratado), 'percentual' => Sql::pct($r->contratado, $total),
            'itens' => (int) $r->itens, 'compras' => (int) $r->linhas, 'processos' => (int) $r->processos,
            'orgaos' => (int) $r->orgaos, 'fornecedores' => (int) $r->fornecedores,
            'filtro' => $r->id !== null ? 'classe' : null, 'chave' => $r->id !== null ? "c:$r->id" : null,
        ])->values();

        return [
            'resumo' => [
                'contratado' => Sql::valor($total),
                'grupos' => $classes->pluck('grupo_id')->filter()->unique()->count(),
                'classes' => $classes->count(),
                'materiais' => Sql::valor($classes->where('tipo', 'Material')->sum('contratado')),
                'servicos' => Sql::valor($classes->where('tipo', 'Serviço')->sum('contratado')),
            ],
            'classes' => $classes->all(),
        ];
    }

    /** Itens da classe, com quantidade e valor unitário médio por unidade de fornecimento. */
    public function classe(int $id, FiltrosAplicados $f): ?array
    {
        $c = DB::table('classes_materiais as cm')->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')
            ->where('cm.id', $id)->first(['cm.id', 'cm.codigo', 'cm.nome', 'gm.nome as grupo']);
        if (! $c) {
            return null;
        }

        return [
            'classe' => ['id' => (int) $c->id, 'codigo' => $c->codigo, 'nome' => $c->nome, 'grupo' => $c->grupo],
            'itens' => $this->natureza->ranking($f->com('classe', ["c:$id"]), 500),
        ];
    }
}
