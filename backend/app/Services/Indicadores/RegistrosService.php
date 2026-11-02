<?php

namespace App\Services\Indicadores;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Detalhamento dos registros que compõem cada indicador (seção 6.1 e 31: clicar e abrir os registros).
 * Usa exatamente os mesmos filtros e critérios do cálculo, para que a lista "feche" com o número.
 */
class RegistrosService
{
    public function __construct(private readonly FiltroService $filtros) {}

    public function listar(string $codigo, FiltrosAplicados $f, OpcoesCalculo $o, int $pagina, int $porPagina, bool $verDadosPessoais): ?LengthAwarePaginator
    {
        $res = IndicadorService::SITUACOES_RESULTADO;

        $resultado = match ($codigo) {
            'total_estimado', 'itens_comprados', 'itens_catalogo' => $this->itens($f, fn ($q) => $q->whereIn('pi.situacao', $res)),
            'total_contratado' => $this->itens($f, fn ($q) => $q->whereNotNull('pi.valor_total_contratado')),
            'total_homologado' => $this->itens($f, fn ($q) => $q->whereNotNull('pi.valor_total_homologado')),
            'total_licitado' => $this->itens($f, fn ($q) => $q->whereIn('pi.situacao', $res)->where('s.tipo', 'LICITACAO')),
            'economia', 'percentual_economia' => $this->itens($f, function ($q) use ($res, $o) {
                $q->whereIn('pi.situacao', $res)->whereNotNull('pi.valor_total_estimado')->whereNotNull($o->colunaReferencia());
                if (! $o->incluirSemDisputa) {
                    $q->where('s.com_disputa', true);
                }
            }),
            'processos', 'unidades_compradoras' => $this->processos($f),
            'qtd_licitacoes' => $this->processos($f, fn ($q) => $q->where('s.tipo', 'LICITACAO')),
            'qtd_dispensas' => $this->processos($f, fn ($q) => $q->where('s.codigo', 'DISPENSA')),
            'qtd_inexigibilidades' => $this->processos($f, fn ($q) => $q->where('s.codigo', 'INEXIGIBILIDADE')),
            'qtd_chamadas_publicas' => $this->processos($f, fn ($q) => $q->where('s.codigo', 'CHAMADA_PNAE')),
            'fornecedores' => $this->fornecedores($f),
            'qtd_atas' => $this->atas($f),
            'contratos_vigentes' => $this->contratos($f, null),
            'contratos_vencendo' => $this->contratos($f, $o->diasVencimentoContratos),
            'total_empenhado' => $this->execucao($f, 'EMPENHO'),
            'total_liquidado' => $this->execucao($f, 'LIQUIDACAO'),
            'total_pago' => $this->execucao($f, 'PAGAMENTO'),
            default => null,
        };

        if ($resultado === null) {
            return null;
        }

        $pag = $resultado->paginate($porPagina, ['*'], 'pagina', $pagina);
        $pag->getCollection()->transform(function ($r) use ($verDadosPessoais) {
            // CPF de pessoa física é dado pessoal (LGPD): mascarado sem a permissão dados_pessoais.ver
            if (! $verDadosPessoais && isset($r->fornecedor_documento, $r->fornecedor_tipo) && $r->fornecedor_tipo === 'F') {
                $r->fornecedor_documento = '***.'.substr($r->fornecedor_documento, 3, 3).'.***-**';
            }
            unset($r->fornecedor_tipo);

            return $r;
        });

        return $pag;
    }

    private function itens(FiltrosAplicados $f, \Closure $criterio)
    {
        $q = $this->filtros->baseItens($f);
        $criterio($q);

        return $q->select([
            'pi.id', 'p.numero_publicacao', 'p.data_abertura', 'uc.nome as unidade', 's.nome as sistematica',
            'ic.codigo as codigo_item', 'pi.descricao', 'pi.quantidade_adjudicada', 'pi.valor_unitario_adjudicado',
            'pi.valor_total_estimado', 'pi.valor_total_adjudicado', 'pi.valor_total_homologado', 'pi.valor_total_contratado',
            'fo.razao_social as fornecedor', 'fo.documento as fornecedor_documento', 'fo.tipo_pessoa as fornecedor_tipo',
            'pi.situacao', 'p.ficticio',
        ])->orderByDesc('pi.valor_total_estimado');
    }

    private function processos(FiltrosAplicados $f, ?\Closure $criterio = null)
    {
        $ids = $this->filtros->idsProcessos($f);
        $q = DB::table('processos as p')
            ->join('sistematicas_aquisicao as s', 's.id', '=', 'p.sistematica_id')
            ->join('unidades_compradoras as uc', 'uc.id', '=', 'p.unidade_compradora_id')
            ->leftJoin('formas_aquisicao as fa', 'fa.id', '=', 'p.forma_aquisicao_id')
            ->leftJoin('amparos_legais as al', 'al.id', '=', 'p.amparo_legal_id')
            ->whereIn('p.id', $ids);
        if ($criterio) {
            $criterio($q);
        }

        return $q->select([
            'p.id', 'p.numero_publicacao', 'p.numero_processo_sei', 'p.data_abertura', 'uc.nome as unidade',
            's.nome as sistematica', 'fa.nome as forma_aquisicao', 'al.descricao as amparo_legal', 'p.objeto',
            'p.registro_precos', 'p.situacao', 'p.valor_estimado', 'p.valor_contratado', 'p.ficticio',
        ])->orderByDesc('p.data_abertura');
    }

    private function fornecedores(FiltrosAplicados $f)
    {
        return $this->filtros->baseItens($f)
            ->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')
            ->whereNotNull('pi.fornecedor_id')
            ->groupBy('fo.id', 'fo.razao_social', 'fo.documento', 'fo.tipo_pessoa', 'pe.nome', 'mf.nome', 'ef.uf')
            ->select([
                'fo.id', 'fo.razao_social as fornecedor', 'fo.documento as fornecedor_documento', 'fo.tipo_pessoa as fornecedor_tipo',
                'pe.nome as porte', 'mf.nome as municipio', 'ef.uf',
                DB::raw('COUNT(DISTINCT p.id) AS processos'), DB::raw('SUM(pi.valor_total_contratado) AS valor_total_contratado'),
            ])->orderByDesc('valor_total_contratado');
    }

    private function atas(FiltrosAplicados $f)
    {
        return DB::table('atas_registro_precos as a')
            ->join('unidades_compradoras as uc', 'uc.id', '=', 'a.orgao_gerenciador_id')
            ->where('a.situacao', '!=', 'CANCELADA')
            ->whereIn('a.processo_id', $this->filtros->idsProcessos($f))
            ->select(['a.id', 'a.numero', 'a.numero_publicacao', 'uc.nome as orgao_gerenciador', 'a.objeto', 'a.vigencia_inicio',
                'a.vigencia_fim', 'a.situacao', 'a.valor_estimado', 'a.valor_registrado', 'a.ficticio'])
            ->orderBy('a.vigencia_fim');
    }

    private function contratos(FiltrosAplicados $f, ?int $dias)
    {
        $hoje = $f->hoje();
        $q = DB::table('contratos as c')
            ->join('unidades_compradoras as uc', 'uc.id', '=', 'c.unidade_compradora_id')
            ->join('fornecedores as fo', 'fo.id', '=', 'c.fornecedor_id')
            ->where('c.situacao', 'VIGENTE')
            ->where('c.vigencia_fim', '>=', $hoje->toDateString())
            ->whereIn('c.processo_id', $this->filtros->idsProcessos($f->semPeriodo()));
        if ($dias !== null) {
            $q->where('c.vigencia_fim', '<=', $hoje->addDays($dias)->toDateString());
        }

        return $q->select(['c.id', 'c.numero', 'uc.nome as unidade', 'fo.razao_social as fornecedor', 'fo.documento as fornecedor_documento',
            'fo.tipo_pessoa as fornecedor_tipo', 'c.objeto', 'c.valor_inicial', 'c.vigencia_inicio', 'c.vigencia_fim', 'c.situacao', 'c.ficticio'])
            ->orderBy('c.vigencia_fim');
    }

    private function execucao(FiltrosAplicados $f, string $tipo)
    {
        return DB::table('contratos_execucao_financeira as e')
            ->leftJoin('contratos as c', 'c.id', '=', 'e.contrato_id')
            ->where('e.tipo', $tipo)
            ->where(fn ($w) => $w->whereIn('e.processo_id', $this->filtros->idsProcessos($f))->orWhereIn('c.processo_id', $this->filtros->idsProcessos($f)))
            ->select(['e.id', 'e.tipo', 'e.documento', 'e.data', 'e.valor', 'c.numero as contrato', 'e.origem'])
            ->orderByDesc('e.data');
    }
}
