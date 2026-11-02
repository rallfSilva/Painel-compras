<?php

namespace App\Services\Inteligencia;

use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MÓDULO ANÁLISE DE PREÇOS (seção 24).
 *
 * Critérios de comparabilidade: o mesmo item de catálogo e a mesma unidade de fornecimento (nunca se
 * comparam unidades diferentes), dentro da janela de tempo e, quando informada, da faixa de quantidade.
 * A referência é o valor unitário adjudicado de itens com resultado.
 *
 * Os alertas pedem análise humana. Nenhum deles afirma sobrepreço, superfaturamento ou irregularidade.
 */
class PrecosService
{
    public const AVISO = 'Sinalização para análise humana. Não constitui conclusão sobre sobrepreço ou irregularidade.';

    public function __construct(
        private readonly FiltroService $filtros,
        private readonly Configuracoes $config,
    ) {}

    /** @return array{janela: int, mediana: float, cv: float, aumento: float, minimo: int} */
    public function parametros(): array
    {
        return [
            'janela' => max(1, $this->config->int('precos.janela_meses', 24)),
            'mediana' => (float) $this->config->int('precos.alerta_mediana_pct', 30),
            'cv' => (float) $this->config->int('precos.alerta_cv_pct', 35),
            'aumento' => (float) $this->config->int('precos.alerta_aumento_pct', 25),
            'minimo' => max(2, $this->config->int('precos.minimo_observacoes', 3)),
        ];
    }

    private function base(FiltrosAplicados $f, ?int $janelaMeses): Builder
    {
        $q = $this->filtros->baseItens($f)
            ->whereNotNull('pi.item_catalogo_id')->whereNotNull('pi.valor_unitario_adjudicado')
            ->where('pi.valor_unitario_adjudicado', '>', 0)->whereRaw(Sql::resultado());
        if ($janelaMeses) {
            $q->where('p.data_abertura', '>=', $f->hoje()->subMonths($janelaMeses)->toDateString());
        }

        return $q;
    }

    /** Visão geral: itens com maior dispersão de preço na janela, por item e unidade. */
    public function painel(FiltrosAplicados $f, ?int $janelaMeses = null): array
    {
        $par = $this->parametros();
        $janela = $janelaMeses ?? $par['janela'];
        $linhas = $this->base($f, $janela ?: null)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->get(['pi.item_catalogo_id as item_id', 'pi.unidade_medida_id as unidade_id', 'um.sigla as unidade',
                'ic.codigo', 'ic.descricao', 'ic.nome_resumido', 'pi.valor_unitario_adjudicado as preco']);

        $itens = $linhas->groupBy(fn ($r) => $r->item_id.'|'.($r->unidade_id ?? 0))->map(function (Collection $g) use ($par) {
            $p = $g->first();
            $e = self::estatisticas($g->pluck('preco')->map(fn ($v) => (float) $v));
            $alertas = [];
            if ($e['n'] < $par['minimo']) {
                $alertas[] = 'REFERENCIAS_INSUFICIENTES';
            }
            if ($e['n'] >= $par['minimo'] && $e['cv'] !== null && $e['cv'] >= $par['cv']) {
                $alertas[] = 'DISPERSAO_ALTA';
            }

            return ['item_id' => (int) $p->item_id, 'unidade_id' => $p->unidade_id !== null ? (int) $p->unidade_id : null,
                'unidade' => $p->unidade, 'codigo' => $p->codigo, 'descricao' => $p->nome_resumido ?? $p->descricao, 'alertas' => $alertas] + $e;
        });

        $comparaveis = $itens->where('n', '>=', $par['minimo']);

        return [
            'parametros' => $par + ['janela_usada' => $janela],
            'resumo' => [
                'itens' => $itens->count(),
                'referencias' => $linhas->count(),
                'itens_comparaveis' => $comparaveis->count(),
                'dispersao_alta' => $itens->filter(fn ($i) => in_array('DISPERSAO_ALTA', $i['alertas'], true))->count(),
                'referencias_insuficientes' => $itens->filter(fn ($i) => in_array('REFERENCIAS_INSUFICIENTES', $i['alertas'], true))->count(),
            ],
            'maior_dispersao' => $comparaveis->sortByDesc('cv')->values()->take(30)->all(),
            'aviso' => self::AVISO,
        ];
    }

    /** Estatísticas de todos os itens e unidades com preço na janela (relatório de análise de preços). */
    public function itens(FiltrosAplicados $f, ?int $janelaMeses = null): array
    {
        $janela = $janelaMeses ?? $this->parametros()['janela'];

        return $this->base($f, $janela ?: null)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->get(['pi.item_catalogo_id as item_id', 'pi.unidade_medida_id as unidade_id', 'um.sigla as unidade', 'ic.codigo', 'ic.descricao', 'pi.valor_unitario_adjudicado as preco'])
            ->groupBy(fn ($r) => $r->item_id.'|'.($r->unidade_id ?? 0))
            ->map(fn (Collection $g) => ['codigo' => $g->first()->codigo, 'descricao' => $g->first()->descricao, 'unidade' => $g->first()->unidade]
                + self::estatisticas($g->pluck('preco')->map(fn ($v) => (float) $v)))
            ->sortBy('descricao')->values()->all();
    }

    /** Itens de catálogo com preço registrado, para a busca da tela. */
    public function buscarItens(FiltrosAplicados $f, string $termo): array
    {
        $t = '%'.mb_strtolower($termo).'%';

        return $this->base($f, null)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->where(fn ($w) => $w->whereRaw('LOWER(ic.descricao) LIKE ?', [$t])->orWhereRaw('LOWER(ic.nome_resumido) LIKE ?', [$t])->orWhere('ic.codigo', 'like', $termo.'%'))
            ->groupBy('pi.item_catalogo_id', 'pi.unidade_medida_id')
            ->selectRaw('pi.item_catalogo_id AS item_id, pi.unidade_medida_id AS unidade_id, MAX(um.sigla) AS unidade, MAX(ic.codigo) AS codigo,
                MAX(COALESCE(ic.nome_resumido, ic.descricao)) AS descricao, COUNT(*) AS referencias')
            ->orderByDesc('referencias')->limit(20)->get()
            ->map(fn ($r) => ['item_id' => (int) $r->item_id, 'unidade_id' => $r->unidade_id !== null ? (int) $r->unidade_id : null,
                'unidade' => $r->unidade, 'codigo' => $r->codigo, 'descricao' => $r->descricao, 'referencias' => (int) $r->referencias])
            ->all();
    }

    /**
     * Análise de um item numa unidade de fornecimento.
     *
     * @param  array{janela_meses?: ?int, quantidade_min?: ?float, quantidade_max?: ?float}  $criterios
     */
    public function item(FiltrosAplicados $f, int $itemId, ?int $unidadeId, array $criterios): ?array
    {
        $item = DB::table('itens_catalogo as ic')->leftJoin('classes_materiais as cm', 'cm.id', '=', 'ic.classe_id')
            ->where('ic.id', $itemId)->first(['ic.id', 'ic.codigo', 'ic.descricao', 'ic.nome_resumido', 'ic.especificacao', 'cm.nome as classe']);
        if (! $item) {
            return null;
        }
        $par = $this->parametros();
        $janela = array_key_exists('janela_meses', $criterios) ? $criterios['janela_meses'] : $par['janela'];

        // Unidades com preço para o item: a análise usa uma de cada vez.
        $unidades = $this->base($f, $janela)->where('pi.item_catalogo_id', $itemId)
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->groupBy('pi.unidade_medida_id')
            ->selectRaw('pi.unidade_medida_id AS id, MAX(um.sigla) AS sigla, MAX(um.nome) AS nome, COUNT(*) AS referencias')
            ->orderByDesc('referencias')->get()
            ->map(fn ($u) => ['id' => $u->id !== null ? (int) $u->id : null, 'sigla' => $u->sigla ?? 'Não informada', 'nome' => $u->nome, 'referencias' => (int) $u->referencias]);
        if ($unidadeId === null || ! $unidades->contains('id', $unidadeId)) {
            $unidadeId = $unidades->first()['id'] ?? null;
        }

        $q = $this->base($f, $janela)->where('pi.item_catalogo_id', $itemId)
            ->where(fn ($w) => $unidadeId !== null ? $w->where('pi.unidade_medida_id', $unidadeId) : $w->whereNull('pi.unidade_medida_id'))
            ->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id');
        if (($criterios['quantidade_min'] ?? null) !== null) {
            $q->where('pi.quantidade_adjudicada', '>=', $criterios['quantidade_min']);
        }
        if (($criterios['quantidade_max'] ?? null) !== null) {
            $q->where('pi.quantidade_adjudicada', '<=', $criterios['quantidade_max']);
        }
        $refs = $q->orderBy('p.data_abertura')->get([
            'pi.id', 'p.id as processo_id', 'p.numero_publicacao', 'p.data_abertura', 'p.ano', 'o.id as orgao_id', 'o.sigla as orgao',
            'mc.id as municipio_id', 'mc.nome as municipio', 'fo.id as fornecedor_id', 'fo.razao_social as fornecedor', 'pe.nome as porte',
            'pi.quantidade_adjudicada as quantidade', 'pi.valor_unitario_adjudicado as preco', 'pi.valor_unitario_estimado as estimado',
        ])->map(function ($r) {
            $r->preco = (float) $r->preco;
            $r->quantidade = $r->quantidade !== null ? (float) $r->quantidade : null;
            $r->estimado = $r->estimado !== null ? (float) $r->estimado : null;

            return $r;
        });

        $e = self::estatisticas($refs->pluck('preco'));
        $mediana = $e['mediana'];
        $porAno = $this->agrupar($refs, 'ano', 'ano', $mediana)->sortBy('chave')->values();

        $referencias = $refs->map(fn ($r) => [
            'id' => (int) $r->id, 'processo_id' => (int) $r->processo_id, 'numero_publicacao' => $r->numero_publicacao,
            'data' => $r->data_abertura, 'orgao' => $r->orgao, 'municipio' => $r->municipio, 'fornecedor' => $r->fornecedor, 'porte' => $r->porte,
            'quantidade' => $r->quantidade, 'preco' => round($r->preco, 4), 'estimado' => $r->estimado !== null ? round($r->estimado, 4) : null,
            'desvio_mediana' => $mediana ? round(($r->preco - $mediana) / $mediana * 100, 2) : null,
            'sinalizado' => $mediana && $e['n'] >= $par['minimo'] && abs(($r->preco - $mediana) / $mediana * 100) >= $par['mediana'],
        ])->sortByDesc('data')->values();

        return [
            'item' => ['id' => (int) $item->id, 'codigo' => $item->codigo, 'descricao' => $item->descricao, 'nome_resumido' => $item->nome_resumido,
                'especificacao' => $item->especificacao, 'classe' => $item->classe],
            'unidades' => $unidades->values()->all(),
            'unidade_id' => $unidadeId,
            'criterios' => [
                'mesmo_item' => true, 'mesma_unidade' => true, 'janela_meses' => $janela,
                'quantidade_min' => $criterios['quantidade_min'] ?? null, 'quantidade_max' => $criterios['quantidade_max'] ?? null,
                'data_referencia' => $f->hoje()->toDateString(),
            ],
            'estatisticas' => $e,
            'evolucao' => $porAno->all(),
            'por_orgao' => $this->agrupar($refs, 'orgao_id', 'orgao', $mediana)->sortByDesc('n')->values()->all(),
            'por_municipio' => $this->agrupar($refs, 'municipio_id', 'municipio', $mediana)->sortByDesc('n')->values()->all(),
            'por_fornecedor' => $this->agrupar($refs, 'fornecedor_id', 'fornecedor', $mediana)->sortByDesc('n')->values()->all(),
            'estadual_nacional' => [
                'estadual' => $e,
                'nacional' => null,
                'disponivel' => false,
                'mensagem' => 'Fonte nacional (PNCP / Compras.gov.br) não integrada. A comparação fica disponível quando a integração for ativada.',
            ],
            'alertas' => $this->alertas($e, $porAno, $referencias, $par),
            'referencias' => $referencias->all(),
            'aviso' => self::AVISO,
        ];
    }

    private function agrupar(Collection $refs, string $chave, string $nome, ?float $mediana = null): Collection
    {
        return $refs->groupBy(fn ($r) => (string) $r->$chave)->map(function (Collection $g, $k) use ($nome, $mediana) {
            $e = self::estatisticas($g->pluck('preco'));

            return ['chave' => $k === '' ? null : (is_numeric($k) ? (int) $k : $k), 'nome' => $g->first()->$nome ?? 'Não informado',
                'n' => $e['n'], 'minimo' => $e['minimo'], 'maximo' => $e['maximo'], 'media' => $e['media'], 'mediana' => $e['mediana'],
                'desvio_mediana' => $mediana && $e['mediana'] !== null ? round(($e['mediana'] - $mediana) / $mediana * 100, 2) : null];
        })->values();
    }

    private function alertas(array $e, Collection $porAno, Collection $referencias, array $par): array
    {
        $a = [];
        if ($e['n'] < $par['minimo']) {
            $a[] = ['tipo' => 'REFERENCIAS_INSUFICIENTES', 'nivel' => 'atencao',
                'mensagem' => sprintf('Há %d referência(s) de preço; o mínimo para formar referência é %d. Amplie a janela ou a faixa de quantidade.', $e['n'], $par['minimo'])];
        }
        if ($e['n'] >= $par['minimo'] && $e['cv'] !== null && $e['cv'] >= $par['cv']) {
            $a[] = ['tipo' => 'DISPERSAO_ALTA', 'nivel' => 'atencao',
                'mensagem' => sprintf('Coeficiente de variação de %s%%, acima do limite de %s%%. Os preços variam muito entre as compras.', self::br($e['cv']), self::br($par['cv']))];
        }
        $acima = $referencias->filter(fn ($r) => $r['sinalizado'] && $r['desvio_mediana'] > 0)->count();
        $abaixo = $referencias->filter(fn ($r) => $r['sinalizado'] && $r['desvio_mediana'] < 0)->count();
        if ($acima) {
            $a[] = ['tipo' => 'ACIMA_MEDIANA', 'nivel' => 'atencao',
                'mensagem' => sprintf('%d compra(s) com preço %s%% ou mais acima da mediana.', $acima, self::br($par['mediana']))];
        }
        if ($abaixo) {
            $a[] = ['tipo' => 'ABAIXO_MEDIANA', 'nivel' => 'informacao',
                'mensagem' => sprintf('%d compra(s) com preço %s%% ou mais abaixo da mediana. Vale verificar especificação e exequibilidade.', $abaixo, self::br($par['mediana']))];
        }
        $anos = $porAno->filter(fn ($x) => $x['mediana'] !== null)->values();
        for ($i = 1; $i < $anos->count(); $i++) {
            $antes = $anos[$i - 1]['mediana'];
            $depois = $anos[$i]['mediana'];
            if ($antes > 0 && ($depois - $antes) / $antes * 100 >= $par['aumento']) {
                $a[] = ['tipo' => 'AUMENTO_BRUSCO', 'nivel' => 'atencao',
                    'mensagem' => sprintf('A mediana subiu %s%% de %s para %s (limite de %s%%).', self::br(($depois - $antes) / $antes * 100), $anos[$i - 1]['chave'], $anos[$i]['chave'], self::br($par['aumento']))];
            }
        }

        return array_map(fn ($x) => $x + ['aviso' => self::AVISO], $a);
    }

    private static function br(float $v): string
    {
        return number_format($v, 1, ',', '.');
    }

    /**
     * Mínimo, máximo, média, mediana, desvio padrão amostral, coeficiente de variação (%) e
     * variação entre o menor e o maior preço (%).
     *
     * @param  Collection<int, float>  $v
     */
    public static function estatisticas(Collection $v): array
    {
        $n = $v->count();
        if ($n === 0) {
            return ['n' => 0, 'minimo' => null, 'maximo' => null, 'media' => null, 'mediana' => null, 'desvio' => null, 'cv' => null, 'variacao' => null];
        }
        $ord = $v->sort()->values();
        $media = $ord->avg();
        $mediana = $n % 2 ? $ord[intdiv($n, 2)] : ($ord[$n / 2 - 1] + $ord[$n / 2]) / 2;
        $desvio = $n > 1 ? sqrt($ord->sum(fn ($x) => ($x - $media) ** 2) / ($n - 1)) : null;
        $min = $ord->first();
        $max = $ord->last();

        return [
            'n' => $n, 'minimo' => round($min, 4), 'maximo' => round($max, 4), 'media' => round($media, 4), 'mediana' => round($mediana, 4),
            'desvio' => $desvio !== null ? round($desvio, 4) : null,
            'cv' => $desvio !== null && $media > 0 ? round($desvio / $media * 100, 2) : null,
            'variacao' => $min > 0 ? round(($max - $min) / $min * 100, 2) : null,
        ];
    }
}
