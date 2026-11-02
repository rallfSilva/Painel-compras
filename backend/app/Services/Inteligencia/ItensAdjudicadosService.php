<?php

namespace App\Services\Inteligencia;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Services\Indicadores\IndicadorService;
use App\Support\DadosPessoais;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * MÓDULO ITENS ADJUDICADOS (seção 16): tabela detalhada dos itens com resultado, com os campos do prompt.
 * % economia do item = (estimado − adjudicado) / estimado × 100, quando os dois valores existem.
 * CPF de pessoa física sai mascarado sem a permissão dados_pessoais.ver.
 */
class ItensAdjudicadosService
{
    private const ORDENACAO = [
        'numero_publicacao' => 'p.numero_publicacao', 'data_abertura' => 'p.data_abertura', 'unidade' => 'uc.nome',
        'descricao' => 'pi.descricao', 'codigo_item' => 'ic.codigo', 'quantidade_adjudicada' => 'pi.quantidade_adjudicada',
        'valor_unitario_adjudicado' => 'pi.valor_unitario_adjudicado', 'valor_total_adjudicado' => 'pi.valor_total_adjudicado',
        'percentual_economia' => 'percentual_economia', 'fornecedor' => 'fo.razao_social', 'data_adjudicacao' => 'pi.data_adjudicacao',
    ];

    public function __construct(private readonly FiltroService $filtros) {}

    /**
     * @param  array{busca: string, situacao: list<string>}  $local
     * @param  list<array{dimensao: string, valor: ?string}>  $caminho  nó da árvore de decomposição (19.1)
     */
    public function listar(FiltrosAplicados $f, array $local, string $ordem, string $direcao, int $pagina, int $porPagina, bool $verDadosPessoais, array $caminho = []): LengthAwarePaginator
    {
        $q = AnaliseTotalService::juntar($this->filtros->baseItens($f))
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id');
        AnaliseTotalService::aplicarCaminho($q, $caminho);

        // Só itens com resultado (adjudicado, homologado ou contratado); a situação local estreita entre eles.
        $q->whereRaw(Sql::resultado());
        if ($sit = array_values(array_intersect($local['situacao'], IndicadorService::SITUACOES_RESULTADO))) {
            $q->whereIn('pi.situacao', $sit);
        }
        if ($local['busca'] !== '') {
            $this->buscar($q, $local['busca']);
        }

        $pag = $q->selectRaw("pi.id, pi.processo_id, p.numero_publicacao, p.numero_processo_sei AS numero_processo, p.numero_licitacao AS numero_edital,
                p.data_abertura, uc.nome AS unidade, o.sigla AS orgao, mc.nome AS municipio_unidade, pi.numero_item, pi.lote,
                COALESCE(pi.codigo_material_sufixo, ic.codigo_sufixo) AS codigo_sufixo, ic.codigo AS codigo_item,
                CASE WHEN ic.tipo = 'S' THEN 'Serviço' WHEN ic.tipo = 'M' THEN 'Material' END AS tipo_item,
                pi.descricao, um.sigla AS unidade_fornecimento, COALESCE(fa.nome, s.nome) AS sistematica,
                p.registro_precos, p.julgamento_lote, pi.quantidade_estimada, pi.quantidade_adjudicada,
                pi.valor_unitario_estimado, pi.valor_unitario_adjudicado, pi.valor_total_estimado, pi.valor_total_adjudicado,
                CASE WHEN pi.valor_total_estimado > 0 AND pi.valor_total_adjudicado IS NOT NULL
                    THEN (pi.valor_total_estimado - pi.valor_total_adjudicado) * 100.0 / pi.valor_total_estimado END AS percentual_economia,
                fo.id AS fornecedor_id, fo.razao_social AS fornecedor, fo.documento AS fornecedor_documento, fo.tipo_pessoa AS fornecedor_tipo,
                pe.nome AS porte, ef.uf AS uf_fornecedor, mf.nome AS municipio_fornecedor, pi.data_adjudicacao, pi.situacao,
                (SELECT MIN(c.numero) FROM contratos_itens ci JOIN contratos c ON c.id = ci.contrato_id WHERE ci.processo_item_id = pi.id) AS numero_contrato,
                (SELECT MIN(a.numero) FROM atas_itens ai JOIN atas_registro_precos a ON a.id = ai.ata_id WHERE ai.processo_item_id = pi.id) AS numero_ata")
            ->orderBy(self::ORDENACAO[$ordem] ?? 'p.data_abertura', $direcao === 'asc' ? 'asc' : 'desc')
            ->orderBy('pi.id', 'desc')
            ->paginate($porPagina, ['*'], 'pagina', $pagina);

        $pag->getCollection()->transform(function ($r) use ($verDadosPessoais) {
            $r = DadosPessoais::mascararLinha($r, $verDadosPessoais);
            $r->registro_precos = (bool) $r->registro_precos;
            $r->lote = $r->lote !== null || (bool) $r->julgamento_lote;
            unset($r->julgamento_lote);
            $r->percentual_economia = $r->percentual_economia !== null ? round((float) $r->percentual_economia, 2) : null;
            foreach (['quantidade_estimada', 'quantidade_adjudicada', 'valor_unitario_estimado', 'valor_unitario_adjudicado', 'valor_total_estimado', 'valor_total_adjudicado'] as $c) {
                $r->$c = $r->$c !== null ? (float) $r->$c : null;
            }

            return $r;
        });

        return $pag;
    }

    /** Consulta por fornecedor, item, órgão, processo e número de publicação. */
    private function buscar(Builder $q, string $busca): void
    {
        $t = '%'.mb_strtolower($busca).'%';
        $digitos = preg_replace('/\D/', '', $busca);
        $q->where(function (Builder $w) use ($t, $digitos) {
            foreach (['pi.descricao', 'ic.codigo', 'fo.razao_social', 'o.sigla', 'o.nome', 'uc.nome', 'p.numero_publicacao', 'p.numero_processo_sei', 'p.numero_licitacao'] as $col) {
                $w->orWhereRaw("LOWER($col) LIKE ?", [$t]);
            }
            if (strlen($digitos) >= 8) {
                $w->orWhere(fn ($x) => $x->where('fo.tipo_pessoa', 'J')->where('fo.documento', 'like', "%$digitos%"));
            }
        });
    }
}
