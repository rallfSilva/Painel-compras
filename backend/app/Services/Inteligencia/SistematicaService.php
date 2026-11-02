<?php

namespace App\Services\Inteligencia;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Services\Indicadores\OpcoesCalculo;
use Illuminate\Support\Facades\DB;

/**
 * PAINEL POR SISTEMÁTICA DE AQUISIÇÃO / TIPO DE PROCESSAMENTO (seção 11).
 *
 * Valores e economia vêm dos itens; situação, tempo e forma (eletrônica/presencial) vêm dos processos.
 * % disputa efetiva = processos com 2 ou mais participantes / processos da sistemática.
 * Tempo médio de conclusão = dias entre a abertura e a homologação, nos processos concluídos.
 * A série comparável entre regimes usa a tabela equivalencias_regimes sem alterar a classificação original.
 */
class SistematicaService
{
    public const TIPOS = [
        'LICITACAO' => 'Modalidade de licitação',
        'CONTRATACAO_DIRETA' => 'Contratação direta',
        'CHAMADA_PUBLICA' => 'Chamada pública',
        'PROCEDIMENTO_AUXILIAR' => 'Procedimento auxiliar',
        'OUTRO' => 'Outros procedimentos históricos',
    ];

    public const SITUACOES = ['CONCLUIDO', 'EM_ANDAMENTO', 'DESERTO', 'FRACASSADO', 'REVOGADO', 'ANULADO', 'SUSPENSO'];

    public function __construct(private readonly FiltroService $filtros) {}

    public function painel(FiltrosAplicados $f, OpcoesCalculo $o): array
    {
        $res = Sql::resultado();
        $ref = $o->colunaReferencia();
        $disputa = $o->incluirSemDisputa ? '1 = 1' : 's.com_disputa = 1';
        $comp = "$res AND pi.valor_total_estimado IS NOT NULL AND $ref IS NOT NULL AND $disputa";

        $valores = $this->filtros->baseItens($f)->groupBy('s.id')
            ->selectRaw("s.id, SUM(pi.valor_total_contratado) AS contratado,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado - $ref END) AS economia,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado END) AS estimado_comparavel")
            ->get()->keyBy('id');

        $processos = DB::table('processos as p')->whereIn('p.id', $this->filtros->idsProcessos($f));
        $porSist = (clone $processos)->join('sistematicas_aquisicao as s', 's.id', '=', 'p.sistematica_id')
            ->groupBy('s.id')
            ->selectRaw('s.id, MAX(s.nome) AS nome, MAX(s.tipo) AS tipo, MAX(s.com_disputa) AS com_disputa, MAX(s.ordem) AS ordem, COUNT(*) AS processos,
                SUM(CASE WHEN p.eletronico = 1 THEN 1 ELSE 0 END) AS eletronicos,
                SUM(CASE WHEN p.registro_precos = 1 THEN 1 ELSE 0 END) AS rp,
                SUM(CASE WHEN p.quantidade_participantes >= 2 THEN 1 ELSE 0 END) AS com_concorrencia,
                AVG(p.quantidade_participantes) AS media_participantes,
                '.collect(self::SITUACOES)->map(fn ($s) => "SUM(CASE WHEN p.situacao = '$s' THEN 1 ELSE 0 END) AS sit_$s")->implode(', '))
            ->orderBy('ordem')->get();

        $tempos = $this->tempos((clone $processos)->where('p.situacao', 'CONCLUIDO')->whereNotNull('p.data_homologacao')
            ->get(['p.sistematica_id', 'p.data_abertura', 'p.data_homologacao']));
        $total = (int) $porSist->sum('processos');

        $linhas = $porSist->map(function ($r) use ($valores, $tempos, $total) {
            $v = $valores[$r->id] ?? null;

            return [
                'id' => (int) $r->id, 'nome' => $r->nome, 'tipo' => $r->tipo, 'tipo_nome' => self::TIPOS[$r->tipo] ?? $r->tipo,
                'com_disputa' => (bool) $r->com_disputa,
                'processos' => (int) $r->processos, 'percentual_processos' => Sql::pct($r->processos, $total),
                'contratado' => Sql::valor($v->contratado ?? null), 'economia' => Sql::valor($v->economia ?? null),
                'percentual_economia' => Sql::pct($v->economia ?? 0, $v->estimado_comparavel ?? 0),
                'percentual_disputa' => Sql::pct($r->com_concorrencia, $r->processos),
                'media_participantes' => $r->media_participantes !== null ? round((float) $r->media_participantes, 1) : null,
                'eletronicos' => (int) $r->eletronicos, 'presenciais' => (int) $r->processos - (int) $r->eletronicos,
                'registro_precos' => (int) $r->rp,
                'situacoes' => collect(self::SITUACOES)->mapWithKeys(fn ($s) => [$s => (int) $r->{"sit_$s"}])->all(),
                'tempo_medio_dias' => $tempos[$r->id] ?? null,
                'filtro' => 'sistematica', 'chave' => "s:$r->id",
            ];
        })->values();

        $todos = $this->tempos((clone $processos)->where('p.situacao', 'CONCLUIDO')->whereNotNull('p.data_homologacao')
            ->get(['p.sistematica_id', 'p.data_abertura', 'p.data_homologacao']), true);

        return [
            'resumo' => [
                'processos' => $total,
                'situacoes' => collect(self::SITUACOES)->map(fn ($s) => ['situacao' => $s, 'quantidade' => (int) $linhas->sum(fn ($l) => $l['situacoes'][$s])])->all(),
                'tempo_medio_dias' => $todos,
                'eletronicos' => (int) $linhas->sum('eletronicos'),
                'registro_precos' => (int) $linhas->sum('registro_precos'),
                'percentual_disputa' => Sql::pct($porSist->sum('com_concorrencia'), $total),
            ],
            'por_sistematica' => $linhas->all(),
            'por_forma' => $this->porForma($f),
            'por_tipo' => $linhas->groupBy('tipo')->map(fn ($g, $tipo) => [
                'tipo' => $tipo, 'nome' => self::TIPOS[$tipo] ?? $tipo, 'processos' => $g->sum('processos'), 'contratado' => Sql::valor($g->sum('contratado')),
                'percentual' => Sql::pct($g->sum('contratado'), $linhas->sum('contratado')),
            ])->sortByDesc('contratado')->values()->all(),
            'evolucao_tipo' => $this->evolucaoTipo($f),
            'evolucao_regime' => $this->evolucaoRegime($f),
            'equivalencias' => $this->equivalencias(),
            'serie_comparavel' => $this->serieComparavel($f),
        ];
    }

    /** @return array<int, float>|float|null */
    private function tempos($linhas, bool $geral = false): array|float|null
    {
        $dias = fn ($r) => (strtotime($r->data_homologacao) - strtotime($r->data_abertura)) / 86400;
        if ($geral) {
            return $linhas->isNotEmpty() ? round($linhas->avg($dias), 1) : null;
        }

        return $linhas->groupBy('sistematica_id')->map(fn ($g) => round($g->avg($dias), 1))->all();
    }

    /** Forma de processamento (ex.: pregão eletrônico x presencial). */
    private function porForma(FiltrosAplicados $f): array
    {
        $linhas = DB::table('processos as p')->whereIn('p.id', $this->filtros->idsProcessos($f))
            ->join('formas_aquisicao as fa', 'fa.id', '=', 'p.forma_aquisicao_id')
            ->join('sistematicas_aquisicao as s', 's.id', '=', 'fa.sistematica_id')
            ->groupBy('fa.id')
            ->selectRaw('fa.id, MAX(fa.nome) AS nome, MAX(s.nome) AS sistematica, COUNT(*) AS processos, SUM(p.valor_contratado) AS contratado')
            ->orderByDesc('contratado')->get();
        $total = (float) $linhas->sum('contratado');

        return $linhas->map(fn ($r) => ['id' => (int) $r->id, 'nome' => $r->nome, 'sistematica' => $r->sistematica, 'processos' => (int) $r->processos,
            'contratado' => Sql::valor($r->contratado), 'percentual' => Sql::pct($r->contratado, $total), 'filtro' => 'sistematica', 'chave' => "f:$r->id"])
            ->all();
    }

    private function evolucaoTipo(FiltrosAplicados $f): array
    {
        $tipos = array_keys(self::TIPOS);

        return DB::table('processos as p')->whereIn('p.id', $this->filtros->idsProcessos($f))
            ->join('sistematicas_aquisicao as s', 's.id', '=', 'p.sistematica_id')
            ->groupBy('p.ano')->orderBy('p.ano')
            ->selectRaw('p.ano, '.collect($tipos)->map(fn ($t) => "SUM(CASE WHEN s.tipo = '$t' THEN 1 ELSE 0 END) AS $t")->implode(', '))
            ->get()
            ->map(fn ($r) => ['ano' => (int) $r->ano] + collect($tipos)->mapWithKeys(fn ($t) => [$t => (int) $r->{$t}])->all())
            ->all();
    }

    private function evolucaoRegime(FiltrosAplicados $f): array
    {
        $regimes = DB::table('regimes_legais')->orderBy('id')->get(['id', 'codigo', 'nome']);
        $linhas = DB::table('processos as p')->whereIn('p.id', $this->filtros->idsProcessos($f))
            ->groupBy('p.ano', 'p.regime_legal_id')->selectRaw('p.ano, p.regime_legal_id, COUNT(*) AS n')->get();
        $anos = $linhas->pluck('ano')->unique()->sort()->values();

        return [
            'regimes' => $regimes->map(fn ($r) => ['codigo' => $r->codigo, 'nome' => $r->nome])->all(),
            'anos' => $anos->map(fn ($ano) => ['ano' => (int) $ano] + $regimes->mapWithKeys(fn ($r) => [
                $r->codigo => (int) ($linhas->first(fn ($l) => $l->ano == $ano && $l->regime_legal_id == $r->id)->n ?? 0),
            ])->all())->all(),
        ];
    }

    /** Tabela de equivalência entre modalidades dos regimes (seção 11). */
    public function equivalencias(): array
    {
        return DB::table('equivalencias_regimes as e')
            ->where('e.tipo', 'MODALIDADE')
            ->join('classificacoes_modalidades as mo', 'mo.id', '=', 'e.origem_id')
            ->join('classificacoes_modalidades as me', 'me.id', '=', 'e.equivalente_id')
            ->leftJoin('regimes_legais as ro', 'ro.id', '=', 'mo.regime_legal_id')
            ->leftJoin('regimes_legais as re', 're.id', '=', 'me.regime_legal_id')
            ->orderBy('mo.id')
            ->get(['mo.nome as origem', 'ro.nome as regime_origem', 'me.nome as equivalente', 're.nome as regime_equivalente', 'e.observacao'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Processos por modalidade equivalente da Lei 14.133 e por ano, somando os dois regimes. */
    private function serieComparavel(FiltrosAplicados $f): array
    {
        $linhas = DB::table('processos as p')->whereIn('p.id', $this->filtros->idsProcessos($f))
            ->join('classificacoes_modalidades as mo', 'mo.id', '=', 'p.modalidade_id')
            ->leftJoin('equivalencias_regimes as e', fn ($j) => $j->on('e.origem_id', '=', 'mo.id')->where('e.tipo', 'MODALIDADE'))
            ->leftJoin('classificacoes_modalidades as me', 'me.id', '=', 'e.equivalente_id')
            ->groupByRaw('p.ano, COALESCE(me.nome, mo.nome)')
            ->selectRaw('p.ano, COALESCE(me.nome, mo.nome) AS modalidade, COUNT(*) AS processos,
                SUM(CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END) AS regime_anterior')
            ->orderBy('p.ano')->get();

        return [
            'modalidades' => $linhas->pluck('modalidade')->unique()->values()->all(),
            'linhas' => $linhas->map(fn ($r) => ['ano' => (int) $r->ano, 'modalidade' => $r->modalidade, 'processos' => (int) $r->processos, 'regime_anterior' => (int) $r->regime_anterior])->all(),
        ];
    }
}
