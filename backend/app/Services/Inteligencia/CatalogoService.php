<?php

namespace App\Services\Inteligencia;

use App\Support\Normalizador;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MÓDULO CATÁLOGO DE BENS E SERVIÇOS (seção 17).
 *
 * - Duplicidade: mesma descrição normalizada e mesma unidade de fornecimento.
 * - Semelhante: termos parecidos (Jaccard ≥ 0,5) na mesma classe.
 * - Equivalência: nunca automática. É proposta e só vale depois de validação técnica com justificativa;
 *   itens com especificações técnicas diferentes exigem confirmação explícita.
 * - Toda criação e edição grava o antes/depois em historico_alteracoes.
 */
class CatalogoService
{
    public const STATUS = ['ATIVO', 'INATIVO', 'SUSPENSO'];

    private const ORDENACAO = ['codigo' => 'ic.codigo', 'descricao' => 'ic.descricao', 'classe' => 'cm.nome', 'compras' => 'compras', 'created_at' => 'ic.created_at'];

    private function base(): Builder
    {
        return DB::table('itens_catalogo as ic')
            ->leftJoin('classes_materiais as cm', 'cm.id', '=', 'ic.classe_id')
            ->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')
            ->leftJoin('classificacoes_natureza as cn', 'cn.id', '=', 'ic.classificacao_natureza_id')
            ->leftJoin('subelementos_despesa as sd', 'sd.id', '=', 'ic.subelemento_despesa_id')
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'ic.unidade_medida_id');
    }

    private const CAMPOS = ['ic.id', 'ic.codigo', 'ic.codigo_sufixo', 'ic.descricao', 'ic.nome_resumido', 'ic.descricao_normalizada', 'ic.tipo',
        'ic.categoria_alimento', 'gm.id as grupo_id', 'gm.nome as grupo', 'cm.id as classe_id', 'cm.nome as classe', 'cn.id as natureza_id', 'cn.nome as natureza',
        'sd.id as subelemento_id', 'sd.codigo as subelemento_codigo', 'sd.nome as subelemento', 'um.id as unidade_id', 'um.sigla as unidade',
        'ic.especificacao', 'ic.status', 'ic.created_at', 'ic.updated_at', 'ic.ficticio'];

    /** @param array{busca: string, status?: list<string>, grupo_id?: ?int, classe_id?: ?int, apenas_duplicados?: bool} $local */
    public function listar(array $local, string $ordem, string $direcao, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $duplicados = $this->chavesDuplicadas();
        $q = $this->base()
            ->when($local['busca'] !== '', function (Builder $q) use ($local) {
                $n = '%'.Normalizador::descricao($local['busca']).'%';
                $q->where(fn ($w) => $w->where('ic.codigo', 'like', $local['busca'].'%')->orWhere('ic.codigo_sufixo', 'like', $local['busca'].'%')
                    ->orWhere('ic.descricao_normalizada', 'like', $n));
            })
            ->when($local['status'] ?? [], fn ($q, $s) => $q->whereIn('ic.status', $s))
            ->when($local['grupo_id'] ?? null, fn ($q, $g) => $q->where('cm.grupo_id', $g))
            ->when($local['classe_id'] ?? null, fn ($q, $c) => $q->where('ic.classe_id', $c))
            ->when($local['apenas_duplicados'] ?? false, fn ($q) => $q->whereExists(fn ($w) => $w->from('itens_catalogo as d')
                ->whereColumn('d.id', '!=', 'ic.id')->whereColumn('d.descricao_normalizada', 'ic.descricao_normalizada')
                ->where(fn ($u) => $u->whereColumn('d.unidade_medida_id', 'ic.unidade_medida_id')
                    ->orWhere(fn ($n) => $n->whereNull('d.unidade_medida_id')->whereNull('ic.unidade_medida_id')))))
            ->select(self::CAMPOS)
            ->selectRaw('(SELECT COUNT(*) FROM processos_itens pi WHERE pi.item_catalogo_id = ic.id) AS compras')
            ->orderBy(self::ORDENACAO[$ordem] ?? 'ic.codigo', $direcao)->orderBy('ic.id');

        $p = $q->paginate($porPagina, ['*'], 'pagina', $pagina);
        $p->getCollection()->transform(fn ($r) => $this->linha($r, $duplicados));

        return $p;
    }

    /** Chaves "descrição normalizada|unidade" que aparecem em mais de um item. */
    private function chavesDuplicadas(): array
    {
        return DB::table('itens_catalogo')->whereNotNull('descricao_normalizada')
            ->groupBy('descricao_normalizada', 'unidade_medida_id')->havingRaw('COUNT(*) > 1')
            ->get(['descricao_normalizada', 'unidade_medida_id'])
            ->map(fn ($r) => $r->descricao_normalizada.'|'.($r->unidade_medida_id ?? 0))->all();
    }

    private function linha(object $r, array $duplicados): array
    {
        $a = (array) $r;
        $a['tipo'] = $r->tipo === 'S' ? 'Serviço' : 'Material';
        $a['ficticio'] = (bool) $r->ficticio;
        $a['compras'] = (int) ($r->compras ?? 0);
        $a['duplicado'] = in_array($r->descricao_normalizada.'|'.($r->unidade_id ?? 0), $duplicados, true);

        return $a;
    }

    public function resumo(): array
    {
        $dup = $this->chavesDuplicadas();
        $porStatus = DB::table('itens_catalogo')->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status');

        return [
            'itens' => (int) $porStatus->sum(),
            'por_status' => collect(self::STATUS)->map(fn ($s) => ['status' => $s, 'quantidade' => (int) ($porStatus[$s] ?? 0)])->all(),
            'grupos_duplicidade' => count($dup),
            'itens_sem_compra' => DB::table('itens_catalogo as ic')->whereNotExists(fn ($w) => $w->from('processos_itens as pi')->whereColumn('pi.item_catalogo_id', 'ic.id'))->count(),
            'equivalencias_pendentes' => DB::table('itens_equivalencias')->where('status', 'PROPOSTA')->count(),
            'equivalencias_validadas' => DB::table('itens_equivalencias')->where('status', 'VALIDADA')->count(),
        ];
    }

    public function detalhe(int $id): ?array
    {
        $r = $this->base()->where('ic.id', $id)->select(self::CAMPOS)
            ->selectRaw('(SELECT COUNT(*) FROM processos_itens pi WHERE pi.item_catalogo_id = ic.id) AS compras')->first();
        if (! $r) {
            return null;
        }
        $item = $this->linha($r, $this->chavesDuplicadas());
        $historico = DB::table('historico_alteracoes as h')->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->where('h.tabela', 'itens_catalogo')->where('h.registro_id', $id)->orderByDesc('h.id')->limit(50)
            ->get(['h.id', 'h.antes', 'h.depois', 'h.created_at', 'u.name as usuario'])
            ->map(fn ($h) => ['id' => (int) $h->id, 'usuario' => $h->usuario, 'data' => $h->created_at,
                'alteracoes' => $this->diferencas(json_decode($h->antes ?? 'null', true) ?? [], json_decode($h->depois ?? 'null', true) ?? [])])
            ->all();

        return [
            'item' => $item,
            'historico' => $historico,
            'duplicados' => $this->duplicadosDe($r),
            'semelhantes' => $this->semelhantes($r),
            'equivalencias' => $this->equivalenciasDe($id),
        ];
    }

    private function diferencas(array $antes, array $depois): array
    {
        $campos = array_unique(array_merge(array_keys($antes), array_keys($depois)));

        return collect($campos)->filter(fn ($c) => ($antes[$c] ?? null) != ($depois[$c] ?? null) && ! in_array($c, ['updated_at', 'created_at', 'descricao_normalizada'], true))
            ->map(fn ($c) => ['campo' => $c, 'antes' => $antes[$c] ?? null, 'depois' => $depois[$c] ?? null])->values()->all();
    }

    private function duplicadosDe(object $r): array
    {
        return $this->base()->where('ic.id', '!=', $r->id)->where('ic.descricao_normalizada', $r->descricao_normalizada)
            ->where(fn ($w) => $r->unidade_id ? $w->where('ic.unidade_medida_id', $r->unidade_id) : $w->whereNull('ic.unidade_medida_id'))
            ->get(['ic.id', 'ic.codigo', 'ic.descricao', 'um.sigla as unidade', 'ic.especificacao', 'ic.status'])
            ->map(fn ($x) => (array) $x + ['especificacao_diferente' => trim((string) $x->especificacao) !== trim((string) $r->especificacao)])->all();
    }

    /** Itens da mesma classe com descrição parecida (não inclui as duplicidades exatas). */
    public function semelhantes(object $r, float $minimo = 0.5): array
    {
        return $this->base()->where('ic.classe_id', $r->classe_id)->where('ic.id', '!=', $r->id)
            ->where('ic.descricao_normalizada', '!=', $r->descricao_normalizada)
            ->get(['ic.id', 'ic.codigo', 'ic.descricao', 'ic.descricao_normalizada', 'um.sigla as unidade', 'ic.unidade_medida_id', 'ic.especificacao'])
            ->map(fn ($x) => [
                'id' => (int) $x->id, 'codigo' => $x->codigo, 'descricao' => $x->descricao, 'unidade' => $x->unidade,
                'semelhanca' => round(Normalizador::semelhanca((string) $r->descricao_normalizada, (string) $x->descricao_normalizada) * 100, 1),
                'mesma_unidade' => (int) $x->unidade_medida_id === (int) $r->unidade_id,
                'especificacao_diferente' => trim((string) $x->especificacao) !== trim((string) $r->especificacao),
            ])
            ->filter(fn ($x) => $x['semelhanca'] >= $minimo * 100)
            ->sortByDesc('semelhanca')->values()->take(20)->all();
    }

    /** Grupos de duplicidade de todo o catálogo. */
    public function duplicidades(): array
    {
        $chaves = DB::table('itens_catalogo')->whereNotNull('descricao_normalizada')
            ->groupBy('descricao_normalizada', 'unidade_medida_id')->havingRaw('COUNT(*) > 1')
            ->get(['descricao_normalizada', 'unidade_medida_id']);

        return $chaves->map(function ($k) {
            $itens = $this->base()->where('ic.descricao_normalizada', $k->descricao_normalizada)
                ->where(fn ($w) => $k->unidade_medida_id ? $w->where('ic.unidade_medida_id', $k->unidade_medida_id) : $w->whereNull('ic.unidade_medida_id'))
                ->selectRaw('ic.id, ic.codigo, ic.descricao, ic.especificacao, ic.status, um.sigla AS unidade,
                    (SELECT COUNT(*) FROM processos_itens pi WHERE pi.item_catalogo_id = ic.id) AS compras')
                ->orderBy('ic.codigo')->get()
                ->map(fn ($x) => ['id' => (int) $x->id, 'codigo' => $x->codigo, 'descricao' => $x->descricao, 'especificacao' => $x->especificacao,
                    'status' => $x->status, 'unidade' => $x->unidade, 'compras' => (int) $x->compras]);

            return [
                'descricao_normalizada' => $k->descricao_normalizada,
                'unidade' => $itens->first()['unidade'] ?? null,
                'especificacoes_diferentes' => $itens->pluck('especificacao')->map(fn ($e) => trim((string) $e))->unique()->count() > 1,
                'itens' => $itens->all(),
            ];
        })->values()->all();
    }

    public function equivalenciasDe(?int $itemId = null, ?string $status = null): array
    {
        return DB::table('itens_equivalencias as e')
            ->join('itens_catalogo as a', 'a.id', '=', 'e.item_id')->join('itens_catalogo as b', 'b.id', '=', 'e.equivalente_id')
            ->leftJoin('users as up', 'up.id', '=', 'e.proposto_por')->leftJoin('users as uv', 'uv.id', '=', 'e.validado_por')
            ->when($itemId, fn ($q, $id) => $q->where(fn ($w) => $w->where('e.item_id', $id)->orWhere('e.equivalente_id', $id)))
            ->when($status, fn ($q, $s) => $q->where('e.status', $s))
            ->orderByDesc('e.id')
            ->get(['e.id', 'e.status', 'e.justificativa', 'e.especificacoes_diferentes', 'e.created_at', 'e.validado_em',
                'a.id as item_id', 'a.codigo as item_codigo', 'a.descricao as item_descricao',
                'b.id as equivalente_id', 'b.codigo as equivalente_codigo', 'b.descricao as equivalente_descricao',
                'up.name as proposto_por', 'uv.name as validado_por'])
            ->map(fn ($e) => (array) $e + [])->map(function ($e) {
                $e['especificacoes_diferentes'] = (bool) $e['especificacoes_diferentes'];

                return $e;
            })->all();
    }

    /** @param array<string, mixed> $dados */
    public function salvar(?int $id, array $dados, int $userId): int
    {
        $dados['descricao_normalizada'] = Normalizador::descricao($dados['descricao']);
        if (! empty($dados['codigo']) && ! empty($dados['unidade_medida_id']) && empty($dados['codigo_sufixo'])) {
            $dados['codigo_sufixo'] = $dados['codigo'].'-'.$dados['unidade_medida_id'];
        }

        return DB::transaction(function () use ($id, $dados, $userId) {
            $antes = $id ? (array) DB::table('itens_catalogo')->where('id', $id)->first() : null;
            if ($id) {
                DB::table('itens_catalogo')->where('id', $id)->update($dados + ['updated_at' => now()]);
            } else {
                $id = DB::table('itens_catalogo')->insertGetId($dados + ['origem' => 'MANUAL', 'created_at' => now(), 'updated_at' => now()]);
            }
            $depois = (array) DB::table('itens_catalogo')->where('id', $id)->first();
            DB::table('historico_alteracoes')->insert(['tabela' => 'itens_catalogo', 'registro_id' => $id, 'user_id' => $userId,
                'antes' => $antes ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null, 'depois' => json_encode($depois, JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
    }

    public function proporEquivalencia(int $itemId, int $equivalenteId, ?string $justificativa, int $userId): int
    {
        if ($itemId === $equivalenteId) {
            throw ValidationException::withMessages(['equivalente_id' => 'Escolha um item diferente.']);
        }
        [$a, $b] = [min($itemId, $equivalenteId), max($itemId, $equivalenteId)];
        $existe = DB::table('itens_equivalencias')->where('item_id', $a)->where('equivalente_id', $b)->first();
        if ($existe && $existe->status !== 'REJEITADA') {
            throw ValidationException::withMessages(['equivalente_id' => 'Já existe uma proposta ou validação para esses itens.']);
        }
        $especs = DB::table('itens_catalogo')->whereIn('id', [$a, $b])->pluck('especificacao')->map(fn ($e) => trim((string) $e))->unique()->count() > 1;
        $valores = ['status' => 'PROPOSTA', 'justificativa' => $justificativa, 'especificacoes_diferentes' => $especs, 'proposto_por' => $userId,
            'validado_por' => null, 'validado_em' => null, 'updated_at' => now()];
        if ($existe) {
            DB::table('itens_equivalencias')->where('id', $existe->id)->update($valores);

            return (int) $existe->id;
        }

        return DB::table('itens_equivalencias')->insertGetId($valores + ['item_id' => $a, 'equivalente_id' => $b, 'created_at' => now()]);
    }

    public function decidirEquivalencia(int $id, bool $validar, string $justificativa, bool $confirmaEspecificacao, int $userId): void
    {
        $e = DB::table('itens_equivalencias')->where('id', $id)->first();
        if (! $e) {
            abort(404, 'Proposta não encontrada.');
        }
        if ($validar && $e->especificacoes_diferentes && ! $confirmaEspecificacao) {
            throw ValidationException::withMessages(['confirmar_especificacao' => 'Os itens têm especificações técnicas diferentes. Confirme a análise técnica para validar a equivalência.']);
        }
        DB::table('itens_equivalencias')->where('id', $id)->update([
            'status' => $validar ? 'VALIDADA' : 'REJEITADA', 'justificativa' => trim(($e->justificativa ? $e->justificativa."\n" : '').$justificativa),
            'validado_por' => $userId, 'validado_em' => now(), 'updated_at' => now(),
        ]);
        DB::table('logs_auditoria')->insert(['user_id' => $userId, 'acao' => $validar ? 'equivalencia.validar' : 'equivalencia.rejeitar',
            'recurso' => 'itens_equivalencias', 'recurso_id' => $id, 'detalhes' => json_encode(['justificativa' => $justificativa], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** Opções dos formulários: grupos > classes, naturezas, subelementos e unidades. */
    public function opcoes(): array
    {
        return [
            'classes' => DB::table('classes_materiais as cm')->join('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id')
                ->orderBy('gm.nome')->orderBy('cm.nome')->get(['cm.id', 'cm.nome', 'gm.id as grupo_id', 'gm.nome as grupo'])->map(fn ($r) => (array) $r)->all(),
            'naturezas' => DB::table('classificacoes_natureza')->orderBy('nome')->get(['id', 'nome'])->map(fn ($r) => (array) $r)->all(),
            'subelementos' => DB::table('subelementos_despesa')->orderBy('codigo')->get(['id', 'codigo', 'nome'])->map(fn ($r) => (array) $r)->all(),
            'unidades' => DB::table('unidades_medida')->orderBy('sigla')->get(['id', 'sigla', 'nome'])->map(fn ($r) => (array) $r)->all(),
            'status' => self::STATUS,
        ];
    }
}
