<?php

namespace App\Services\Planejamento;

use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Services\Inteligencia\Sql;
use App\Support\DadosPessoais;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * MÓDULO DE CONTRATOS (seção 22).
 *
 * - Vigente: situação VIGENTE e fim da vigência na data de referência ou depois. Vigência é uma situação
 *   atual, por isso o período do filtro não restringe; os demais filtros valem pelo processo de origem.
 * - Acréscimo = Σ aditivos de valor / valor inicial × 100. A Lei 14.133/2021, art. 125, limita acréscimos a
 *   25% (50% em reforma de edifício ou equipamento). O painel sinaliza para análise humana os contratos a
 *   partir de 80% do limite configurado; não afirma irregularidade.
 */
class ContratosService
{
    public const SITUACOES = ['VIGENTE', 'ENCERRADO', 'RESCINDIDO', 'SUSPENSO'];

    private const ORDENACAO = ['numero' => 'c.numero', 'vigencia_fim' => 'c.vigencia_fim', 'valor_inicial' => 'c.valor_inicial',
        'valor_atualizado' => 'valor_atualizado', 'percentual_acrescimo' => 'percentual_acrescimo', 'fornecedor' => 'fo.razao_social', 'data_assinatura' => 'c.data_assinatura'];

    public function __construct(private readonly FiltroService $filtros, private readonly Configuracoes $config) {}

    /** @return array{prazos: list<int>, limite: float, limite_reforma: float, proximo: float} */
    public function parametros(): array
    {
        $prazos = collect(explode(',', (string) $this->config->get('contratos.prazos_alerta', '30,60,90')))
            ->map(fn ($x) => (int) trim($x))->filter(fn ($x) => $x > 0)->unique()->sort()->values()->all();

        return [
            'prazos' => $prazos ?: [30, 60, 90],
            'limite' => (float) $this->config->int('contratos.limite_acrescimo', 25),
            'limite_reforma' => (float) $this->config->int('contratos.limite_acrescimo_reforma', 50),
            'proximo' => (float) $this->config->int('contratos.alerta_proximo_limite', 80),
        ];
    }

    /** Percentual a partir do qual o acréscimo é sinalizado, como literal numérico (o SQLite compara parâmetro float como texto). */
    private static function corte(array $par): string
    {
        return sprintf('%.4F', $par['limite'] * $par['proximo'] / 100);
    }

    private static function aditivosSql(): string
    {
        return "(SELECT COALESCE(SUM(a.valor), 0) FROM contratos_aditivos a WHERE a.contrato_id = c.id AND a.tipo IN ('VALOR', 'MISTO'))";
    }

    public function base(FiltrosAplicados $f): Builder
    {
        $q = DB::table('contratos as c')
            ->join('unidades_compradoras as uc', 'uc.id', '=', 'c.unidade_compradora_id')
            ->join('orgaos as o', 'o.id', '=', 'uc.orgao_id')
            ->join('fornecedores as fo', 'fo.id', '=', 'c.fornecedor_id')
            ->leftJoin('processos as p', 'p.id', '=', 'c.processo_id')
            ->leftJoin('atas_registro_precos as ar', 'ar.id', '=', 'c.ata_id');
        if ($f->orgaosPermitidos !== null) {
            $q->whereIn('o.id', $f->orgaosPermitidos ?: [0]);
        }
        $semPeriodo = $f->semPeriodo();
        if ($semPeriodo->selecoes !== [] && collect($semPeriodo->selecoes)->flatten()->isNotEmpty()) {
            $q->whereIn('c.processo_id', $this->filtros->idsProcessos($semPeriodo));
        }

        return $q;
    }

    public function painel(FiltrosAplicados $f): array
    {
        $par = $this->parametros();
        $hoje = $f->hoje();
        $vig = fn () => $this->base($f)->where('c.situacao', 'VIGENTE')->where('c.vigencia_fim', '>=', $hoje->toDateString());
        $ad = self::aditivosSql();

        $r = $vig()->selectRaw("COUNT(*) AS vigentes, SUM(COALESCE(c.valor_atualizado, c.valor_inicial + $ad)) AS valor_vigente,
            SUM(c.valor_inicial) AS inicial, SUM($ad) AS acrescimos")->first();
        $aditivos = $this->base($f)->join('contratos_aditivos as a', 'a.contrato_id', '=', 'c.id')
            ->selectRaw("COUNT(*) AS quantidade, SUM(CASE WHEN a.tipo IN ('VALOR', 'MISTO') THEN a.valor ELSE 0 END) AS valor")->first();

        $vencendo = [];
        foreach ($par['prazos'] as $d) {
            $vencendo[] = ['dias' => $d, 'quantidade' => $vig()->where('c.vigencia_fim', '<=', $hoje->addDays($d)->toDateString())->count()];
        }
        $sinalizados = $this->base($f)->where('c.valor_inicial', '>', 0)
            ->whereRaw("$ad * 100.0 / c.valor_inicial >= ".self::corte($par))->count();

        return [
            'parametros' => $par,
            'indicadores' => [
                'vigentes' => (int) $r->vigentes, 'valor_vigente' => Sql::valor($r->valor_vigente ?? 0),
                'aditivos' => (int) $aditivos->quantidade, 'valor_aditivos' => Sql::valor($aditivos->valor ?? 0),
                'percentual_acrescimo' => Sql::pct($r->acrescimos, $r->inicial), 'sinalizados' => $sinalizados,
                'total' => $this->base($f)->count(),
            ],
            'vencendo' => $vencendo,
            'por_situacao' => $this->base($f)->groupBy('c.situacao')->selectRaw("c.situacao, COUNT(*) AS quantidade, SUM(COALESCE(c.valor_atualizado, c.valor_inicial + $ad)) AS valor")
                ->get()->map(fn ($x) => ['situacao' => $x->situacao, 'quantidade' => (int) $x->quantidade, 'valor' => Sql::valor($x->valor)])->all(),
            'vencimentos_por_mes' => $vig()->where('c.vigencia_fim', '<=', $hoje->addMonths(12)->toDateString())
                ->get(['c.vigencia_fim'])->groupBy(fn ($x) => substr((string) $x->vigencia_fim, 0, 7))
                ->map(fn ($g, $m) => ['mes' => $m, 'quantidade' => $g->count()])->sortBy('mes')->values()->all(),
            'por_orgao' => $vig()->groupBy('o.id')->selectRaw("o.id, MAX(COALESCE(o.sigla, o.nome)) AS nome, COUNT(*) AS quantidade, SUM(COALESCE(c.valor_atualizado, c.valor_inicial + $ad)) AS valor")
                ->orderByDesc('valor')->limit(15)->get()->map(fn ($x) => ['id' => (int) $x->id, 'nome' => $x->nome, 'quantidade' => (int) $x->quantidade,
                    'contratado' => Sql::valor($x->valor), 'processos' => (int) $x->quantidade, 'percentual' => Sql::pct($x->valor, $r->valor_vigente),
                    'filtro' => 'unidade', 'chave' => "o:$x->id"])->all(),
            'execucao_financeira' => DB::table('contratos_execucao_financeira')->exists()
                ? null : 'Fonte financeira estadual ainda não integrada: empenhado, liquidado e pago aparecem quando a integração for ativada.',
        ];
    }

    /** @param array{busca: string, situacao: list<string>} $local */
    public function listar(FiltrosAplicados $f, array $local, ?int $vencendoEm, bool $apenasSinalizados, string $ordem, string $direcao, int $porPagina, int $pagina, bool $verDadosPessoais): LengthAwarePaginator
    {
        $par = $this->parametros();
        $hoje = $f->hoje();
        $ad = self::aditivosSql();
        $q = $this->base($f);
        if ($sit = array_values(array_intersect($local['situacao'], self::SITUACOES))) {
            $q->whereIn('c.situacao', $sit);
        }
        if ($vencendoEm) {
            $q->where('c.situacao', 'VIGENTE')->whereBetween('c.vigencia_fim', [$hoje->toDateString(), $hoje->addDays($vencendoEm)->toDateString()]);
        }
        if ($apenasSinalizados) {
            $q->where('c.valor_inicial', '>', 0)->whereRaw("$ad * 100.0 / c.valor_inicial >= ".self::corte($par));
        }
        if ($local['busca'] !== '') {
            $t = '%'.mb_strtolower($local['busca']).'%';
            $dig = preg_replace('/\D/', '', $local['busca']);
            $q->where(function ($w) use ($t, $dig) {
                foreach (['c.numero', 'c.objeto', 'fo.razao_social', 'o.sigla', 'uc.nome', 'p.numero_publicacao', 'c.gestor', 'c.fiscal'] as $col) {
                    $w->orWhereRaw("LOWER($col) LIKE ?", [$t]);
                }
                if (strlen($dig) >= 8) {
                    $w->orWhere(fn ($x) => $x->where('fo.tipo_pessoa', 'J')->where('fo.documento', 'like', "%$dig%"));
                }
            });
        }

        $p = $q->selectRaw("c.id, c.numero, c.processo_id, p.numero_publicacao AS processo, ar.numero AS ata, o.sigla AS orgao, uc.nome AS unidade,
                fo.id AS fornecedor_id, fo.razao_social AS fornecedor, fo.documento AS fornecedor_documento, fo.tipo_pessoa AS fornecedor_tipo, c.objeto,
                c.valor_inicial, COALESCE(c.valor_atualizado, c.valor_inicial + $ad) AS valor_atualizado, $ad AS acrescimos,
                CASE WHEN c.valor_inicial > 0 THEN $ad * 100.0 / c.valor_inicial END AS percentual_acrescimo,
                c.data_assinatura, c.vigencia_inicio, c.vigencia_fim, c.situacao, c.gestor, c.fiscal, c.link_pncp,
                (SELECT COUNT(*) FROM contratos_aditivos a2 WHERE a2.contrato_id = c.id) AS aditivos")
            ->orderBy(self::ORDENACAO[$ordem] ?? 'c.vigencia_fim', $direcao)->orderBy('c.id')
            ->paginate($porPagina, ['*'], 'pagina', $pagina);

        $p->getCollection()->transform(function ($r) use ($par, $hoje, $verDadosPessoais) {
            $r = DadosPessoais::mascararLinha($r, $verDadosPessoais);
            foreach (['valor_inicial', 'valor_atualizado', 'acrescimos'] as $c) {
                $r->$c = (float) $r->$c;
            }
            $r->percentual_acrescimo = $r->percentual_acrescimo !== null ? round((float) $r->percentual_acrescimo, 2) : null;
            $r->aditivos = (int) $r->aditivos;
            $r->dias_para_vencer = $r->situacao === 'VIGENTE' ? (int) $hoje->diffInDays($r->vigencia_fim, false) : null;
            $r->sinal_acrescimo = $r->percentual_acrescimo === null ? null
                : ($r->percentual_acrescimo > $par['limite'] ? 'ACIMA' : ($r->percentual_acrescimo >= $par['limite'] * $par['proximo'] / 100 ? 'PROXIMO' : null));

            return $r;
        });

        return $p;
    }

    public function detalhe(int $id, FiltrosAplicados $f, bool $verDadosPessoais): ?array
    {
        $c = $this->base($f)->where('c.id', $id)->selectRaw('c.*, p.numero_publicacao AS processo, ar.numero AS ata, o.sigla AS orgao, uc.nome AS unidade,
            fo.razao_social AS fornecedor, fo.documento AS fornecedor_documento, fo.tipo_pessoa AS fornecedor_tipo')->first();
        if (! $c) {
            return null;
        }
        $c = DadosPessoais::mascararLinha($c, $verDadosPessoais);
        $aditivos = DB::table('contratos_aditivos')->where('contrato_id', $id)->orderBy('data_assinatura')->get()
            ->map(fn ($a) => ['id' => (int) $a->id, 'numero' => $a->numero, 'tipo' => $a->tipo, 'valor' => $a->valor !== null ? (float) $a->valor : null,
                'nova_vigencia_fim' => $a->nova_vigencia_fim, 'data_assinatura' => $a->data_assinatura, 'justificativa' => $a->justificativa,
                'percentual' => in_array($a->tipo, ['VALOR', 'MISTO'], true) ? Sql::pct($a->valor, $c->valor_inicial) : null])->all();
        $itens = DB::table('contratos_itens as ci')->leftJoin('processos_itens as pi', 'pi.id', '=', 'ci.processo_item_id')
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')->where('ci.contrato_id', $id)
            ->get(['ci.id', 'pi.descricao', 'um.sigla as unidade', 'ci.quantidade', 'ci.valor_unitario', 'ci.valor_total'])
            ->map(fn ($i) => ['id' => (int) $i->id, 'descricao' => $i->descricao, 'unidade' => $i->unidade, 'quantidade' => (float) $i->quantidade,
                'valor_unitario' => (float) $i->valor_unitario, 'valor_total' => (float) $i->valor_total])->all();
        $fin = DB::table('contratos_execucao_financeira')->where('contrato_id', $id)->groupBy('tipo')->selectRaw('tipo, SUM(valor) AS valor')->pluck('valor', 'tipo');
        $acresc = collect($aditivos)->whereIn('tipo', ['VALOR', 'MISTO'])->sum('valor');

        return [
            'contrato' => [
                'id' => (int) $c->id, 'numero' => $c->numero, 'processo_id' => $c->processo_id, 'processo' => $c->processo, 'ata' => $c->ata,
                'orgao' => $c->orgao, 'unidade' => $c->unidade, 'fornecedor_id' => (int) $c->fornecedor_id, 'fornecedor' => $c->fornecedor,
                'fornecedor_documento' => $c->fornecedor_documento, 'objeto' => $c->objeto, 'valor_inicial' => (float) $c->valor_inicial,
                'valor_atualizado' => (float) ($c->valor_atualizado ?? ((float) $c->valor_inicial + $acresc)), 'acrescimos' => (float) $acresc,
                'percentual_acrescimo' => Sql::pct($acresc, $c->valor_inicial), 'data_assinatura' => $c->data_assinatura,
                'vigencia_inicio' => $c->vigencia_inicio, 'vigencia_fim' => $c->vigencia_fim, 'situacao' => $c->situacao,
                'gestor' => $c->gestor, 'fiscal' => $c->fiscal, 'link_pncp' => $c->link_pncp, 'ficticio' => (bool) $c->ficticio,
            ],
            'aditivos' => $aditivos,
            'itens' => $itens,
            'financeiro' => $fin->isEmpty() ? null : ['empenhado' => (float) ($fin['EMPENHO'] ?? 0), 'liquidado' => (float) ($fin['LIQUIDACAO'] ?? 0), 'pago' => (float) ($fin['PAGAMENTO'] ?? 0)],
            'parametros' => $this->parametros(),
        ];
    }
}
