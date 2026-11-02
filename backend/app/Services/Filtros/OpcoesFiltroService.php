<?php

namespace App\Services\Filtros;

use Illuminate\Support\Facades\DB;

/**
 * Opções das árvores de filtro. Cada nó: {key, label, children?}. A key é o valor enviado em f[filtro][].
 * Filtros de alta cardinalidade (item, fornecedor, publicação) são buscados sob demanda em buscar().
 */
class OpcoesFiltroService
{
    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public function todas(?array $orgaosPermitidos): array
    {
        return [
            'tempo' => $this->tempo(),
            'porte' => DB::table('portes_empresariais')->orderBy('ordem')->get()->map(fn ($p) => ['key' => "p:$p->id", 'label' => $p->nome])->all(),
            'sistematica' => $this->sistematica(),
            'natureza' => $this->natureza(),
            'classe' => $this->classe(),
            'comprador_local' => $this->compradorLocal(),
            'unidade' => $this->unidade($orgaosPermitidos),
            'rp' => [['key' => 'sim', 'label' => 'Sim'], ['key' => 'nao', 'label' => 'Não']],
            'amparo' => $this->amparo(),
            'fornecedor_local' => $this->fornecedorLocal(),
        ];
    }

    private function tempo(): array
    {
        $anos = DB::table('processos')->distinct()->orderByDesc('ano')->pluck('ano');

        return $anos->map(fn ($a) => [
            'key' => "a:$a", 'label' => (string) $a,
            'children' => array_map(fn ($s) => [
                'key' => "s:$a-$s", 'label' => "{$s}º semestre",
                'children' => array_map(fn ($t) => [
                    'key' => "t:$a-$t", 'label' => "{$t}º trimestre",
                    'children' => array_map(fn ($m) => ['key' => sprintf('m:%d-%02d', $a, $m), 'label' => self::MESES[$m - 1]."/$a"], range($t * 3 - 2, $t * 3)),
                ], $s === 1 ? [1, 2] : [3, 4]),
            ], [1, 2]),
        ])->all();
    }

    private function sistematica(): array
    {
        $formas = DB::table('formas_aquisicao')->orderBy('nome')->get()->groupBy('sistematica_id');

        return DB::table('sistematicas_aquisicao')->orderBy('ordem')->get()->map(fn ($s) => [
            'key' => "s:$s->id", 'label' => $s->nome,
            'children' => ($formas[$s->id] ?? collect())->count() > 1
                ? $formas[$s->id]->map(fn ($f) => ['key' => "f:$f->id", 'label' => $f->nome])->values()->all()
                : null,
        ])->all();
    }

    private function natureza(): array
    {
        $sub = DB::table('subelementos_despesa')->orderBy('codigo')->get()->groupBy('elemento_despesa_id');
        $el = DB::table('elementos_despesa')->orderBy('codigo')->get()->groupBy('natureza_despesa_id');

        return [
            ['key' => 'grupo:tipo', 'label' => 'Tipo de aquisição', 'group' => true,
                'children' => DB::table('classificacoes_natureza')->orderBy('ordem')->get()->map(fn ($n) => ['key' => "n:$n->id", 'label' => $n->nome])->all()],
            ['key' => 'grupo:despesa', 'label' => 'Natureza da despesa', 'group' => true,
                'children' => DB::table('naturezas_despesa')->orderBy('codigo')->get()->map(fn ($n) => [
                    'key' => "nd:$n->id", 'label' => "$n->codigo — $n->nome",
                    'children' => ($el[$n->id] ?? collect())->map(fn ($e) => [
                        'key' => "el:$e->id", 'label' => "$e->codigo — $e->nome",
                        'children' => ($sub[$e->id] ?? collect())->map(fn ($s) => ['key' => "se:$s->id", 'label' => "$s->codigo — $s->nome"])->values()->all(),
                    ])->values()->all(),
                ])->all()],
        ];
    }

    private function classe(): array
    {
        $classes = DB::table('classes_materiais')->orderBy('nome')->get()->groupBy('grupo_id');

        return DB::table('grupos_materiais')->orderBy('nome')->get()->map(fn ($g) => [
            'key' => "g:$g->id", 'label' => $g->nome.($g->tipo === 'S' ? ' (serviço)' : ''),
            'children' => ($classes[$g->id] ?? collect())->map(fn ($c) => ['key' => "c:$c->id", 'label' => $c->nome])->values()->all(),
        ])->all();
    }

    private function compradorLocal(): array
    {
        $municipios = DB::table('municipios as m')->join('unidades_compradoras as uc', 'uc.municipio_id', '=', 'm.id')
            ->distinct()->orderBy('m.nome')->get(['m.id', 'm.nome', 'm.regiao_planejamento_id']);
        $regioes = DB::table('regioes_planejamento')->orderBy('nome')->get();
        $porRegiao = $municipios->groupBy(fn ($m) => $m->regiao_planejamento_id ?? 0);

        $nos = $regioes->map(fn ($r) => [
            'key' => "r:$r->id", 'label' => $r->nome,
            'children' => ($porRegiao[$r->id] ?? collect())->map(fn ($m) => ['key' => "m:$m->id", 'label' => $m->nome])->values()->all(),
        ])->filter(fn ($n) => $n['children'])->values()->all();
        if (isset($porRegiao[0])) {
            $nos[] = ['key' => 'grupo:sem_regiao', 'label' => 'Sem região cadastrada', 'group' => true,
                'children' => $porRegiao[0]->map(fn ($m) => ['key' => "m:$m->id", 'label' => $m->nome])->values()->all()];
        }

        return $nos;
    }

    private function unidade(?array $orgaosPermitidos): array
    {
        $orgaos = DB::table('orgaos')->when($orgaosPermitidos !== null, fn ($q) => $q->whereIn('id', $orgaosPermitidos ?: [0]))->orderBy('nome')->get();
        $unidades = DB::table('unidades_compradoras')->orderBy('nome')->get()->groupBy('orgao_id');
        $porSuperior = $orgaos->groupBy(fn ($o) => $o->unidade_superior_id ?? 0);

        return DB::table('unidades_superiores')->orderBy('nome')->get()
            ->filter(fn ($us) => isset($porSuperior[$us->id]))
            ->map(fn ($us) => [
                'key' => "us:$us->id", 'label' => $us->nome,
                'children' => $porSuperior[$us->id]->map(fn ($o) => [
                    'key' => "o:$o->id", 'label' => $o->nome,
                    'children' => ($unidades[$o->id] ?? collect())->map(fn ($u) => ['key' => "u:$u->id", 'label' => $u->nome.($u->uasg ? " (UASG $u->uasg)" : '')])->values()->all(),
                ])->values()->all(),
            ])->values()->all();
    }

    private function amparo(): array
    {
        $amparos = DB::table('amparos_legais')->orderBy('descricao')->get()->groupBy('regime_legal_id');

        return DB::table('regimes_legais')->orderBy('id')->get()
            ->filter(fn ($r) => isset($amparos[$r->id]))
            ->map(fn ($r) => [
                'key' => "rg:$r->id", 'label' => $r->nome,
                'children' => $amparos[$r->id]->map(fn ($a) => ['key' => "a:$a->id", 'label' => $a->descricao])->values()->all(),
            ])->values()->all();
    }

    private function fornecedorLocal(): array
    {
        $municipios = DB::table('fornecedores as f')->join('municipios as m', 'm.id', '=', 'f.municipio_id')
            ->join('estados as e', 'e.id', '=', 'm.estado_id')->distinct()->orderBy('e.uf')->orderBy('m.nome')
            ->get(['e.uf', 'e.nome as estado', 'm.id', 'm.nome']);

        return $municipios->groupBy('uf')->map(fn ($lista, $uf) => [
            'key' => "uf:$uf", 'label' => $lista->first()->estado." ($uf)",
            'children' => $lista->map(fn ($m) => ['key' => "m:$m->id", 'label' => $m->nome])->values()->all(),
        ])->values()->all();
    }

    /** Busca para os filtros de alta cardinalidade. */
    public function buscar(string $tipo, string $termo, bool $verDadosPessoais): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < 2) {
            return [];
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $termo).'%';

        return match ($tipo) {
            'item' => DB::table('itens_catalogo')->where(fn ($q) => $q->where('codigo', 'like', $like)->orWhere('descricao', 'like', $like))
                ->orderBy('descricao')->limit(30)->get()->map(fn ($i) => ['key' => "i:$i->id", 'label' => "$i->codigo — $i->descricao"])->all(),
            'fornecedor' => DB::table('fornecedores')->where(fn ($q) => $q->where('razao_social', 'like', $like)->orWhere('documento', 'like', preg_replace('/\D/', '', $termo) ? '%'.preg_replace('/\D/', '', $termo).'%' : $like))
                ->orderBy('razao_social')->limit(30)->get()->map(fn ($f) => [
                    'key' => "fo:$f->id",
                    'label' => $f->razao_social.' — '.($f->tipo_pessoa === 'F' && ! $verDadosPessoais ? 'CPF ***.'.substr($f->documento, 3, 3).'.***-**' : self::documento($f->documento)),
                ])->all(),
            'publicacao' => DB::table('processos')->where('numero_publicacao', 'like', $like)->orderByDesc('numero_publicacao')->limit(30)
                ->pluck('numero_publicacao')->map(fn ($n) => ['key' => $n, 'label' => $n])->all(),
            default => [],
        };
    }

    public static function documento(string $doc): string
    {
        return strlen($doc) === 14
            ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $doc)
            : preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $doc);
    }
}
