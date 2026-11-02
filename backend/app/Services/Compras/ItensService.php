<?php

namespace App\Services\Compras;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Support\DadosPessoais;
use Illuminate\Pagination\LengthAwarePaginator;

/** Itens dos processos (Etapa 2: aba Itens do módulo Licitações e Processos). */
class ItensService
{
    public const SITUACOES = ['ADJUDICADO', 'HOMOLOGADO', 'CONTRATADO', 'DESERTO', 'FRACASSADO', 'CANCELADO', 'EM_ANDAMENTO'];

    private const ORDENACAO = [
        'data_abertura' => 'p.data_abertura', 'descricao' => 'pi.descricao', 'codigo_item' => 'ic.codigo',
        'unidade' => 'uc.nome', 'fornecedor' => 'fo.razao_social', 'quantidade_adjudicada' => 'pi.quantidade_adjudicada',
        'valor_unitario_adjudicado' => 'pi.valor_unitario_adjudicado', 'valor_total_estimado' => 'pi.valor_total_estimado',
        'valor_total_contratado' => 'pi.valor_total_contratado', 'situacao' => 'pi.situacao',
    ];

    public function __construct(private readonly FiltroService $filtros) {}

    public function listar(FiltrosAplicados $f, array $local, string $ordem, string $direcao, int $pagina, int $porPagina, bool $verDadosPessoais): LengthAwarePaginator
    {
        $q = $this->filtros->baseItens($f)->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id');

        if ($sit = array_values(array_intersect($local['situacao'] ?? [], self::SITUACOES))) {
            $q->whereIn('pi.situacao', $sit);
        }
        if (($busca = trim((string) ($local['busca'] ?? ''))) !== '') {
            $termo = '%'.mb_strtolower($busca).'%';
            $q->where(fn ($w) => $w->whereRaw('LOWER(pi.descricao) LIKE ?', [$termo])
                ->orWhereRaw('LOWER(ic.codigo) LIKE ?', [$termo])
                ->orWhereRaw('LOWER(p.numero_publicacao) LIKE ?', [$termo]));
        }

        $pag = $q->select([
            'pi.id', 'pi.processo_id', 'p.numero_publicacao', 'p.data_abertura', 'uc.nome as unidade', 's.nome as sistematica',
            'pi.numero_item', 'pi.item_catalogo_id', 'ic.codigo as codigo_item', 'pi.descricao', 'um.sigla as unidade_medida',
            'pi.quantidade_adjudicada', 'pi.valor_unitario_estimado', 'pi.valor_unitario_adjudicado', 'pi.valor_total_estimado',
            'pi.valor_total_adjudicado', 'pi.valor_total_homologado', 'pi.valor_total_contratado',
            'fo.id as fornecedor_id', 'fo.razao_social as fornecedor', 'fo.documento as fornecedor_documento', 'fo.tipo_pessoa as fornecedor_tipo',
            'pi.situacao', 'pi.ficticio',
        ])
            ->orderBy(self::ORDENACAO[$ordem] ?? 'p.data_abertura', $direcao === 'asc' ? 'asc' : 'desc')
            ->orderBy('pi.id', 'desc')
            ->paginate($porPagina, ['*'], 'pagina', $pagina);

        $pag->getCollection()->transform(fn ($r) => DadosPessoais::mascararLinha($r, $verDadosPessoais));

        return $pag;
    }
}
