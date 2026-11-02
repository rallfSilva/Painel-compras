<?php

namespace App\Services\Compras;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Support\DadosPessoais;
use App\Support\FasesProcesso;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Módulo Licitações e Processos (seção 21): lista, resumo por situação e ficha do processo com fases. */
class ProcessosService
{
    public const SITUACOES = ['EM_ANDAMENTO', 'CONCLUIDO', 'DESERTO', 'FRACASSADO', 'REVOGADO', 'ANULADO', 'SUSPENSO'];

    private const ORDENACAO = [
        'data_abertura' => 'p.data_abertura', 'numero_publicacao' => 'p.numero_publicacao', 'unidade' => 'uc.nome',
        'sistematica' => 's.nome', 'situacao' => 'p.situacao', 'valor_estimado' => 'p.valor_estimado',
        'valor_contratado' => 'p.valor_contratado', 'quantidade_itens' => 'quantidade_itens',
        'quantidade_participantes' => 'p.quantidade_participantes',
    ];

    public function __construct(private readonly FiltroService $filtros) {}

    /** Consulta base dos processos que atendem aos filtros globais e aos filtros locais do módulo. */
    private function base(FiltrosAplicados $f, array $local): Builder
    {
        $q = DB::table('processos as p')
            ->join('sistematicas_aquisicao as s', 's.id', '=', 'p.sistematica_id')
            ->join('unidades_compradoras as uc', 'uc.id', '=', 'p.unidade_compradora_id')
            ->join('orgaos as o', 'o.id', '=', 'uc.orgao_id')
            ->whereIn('p.id', $this->filtros->idsProcessos($f));

        if ($sit = array_values(array_intersect($local['situacao'] ?? [], self::SITUACOES))) {
            $q->whereIn('p.situacao', $sit);
        }
        if (($busca = trim((string) ($local['busca'] ?? ''))) !== '') {
            $termo = '%'.mb_strtolower($busca).'%';
            $q->where(fn ($w) => $w->whereRaw('LOWER(p.numero_publicacao) LIKE ?', [$termo])
                ->orWhereRaw('LOWER(p.numero_processo_sei) LIKE ?', [$termo])
                ->orWhereRaw('LOWER(p.numero_licitacao) LIKE ?', [$termo])
                ->orWhereRaw('LOWER(p.objeto) LIKE ?', [$termo]));
        }

        return $q;
    }

    public function listar(FiltrosAplicados $f, array $local, string $ordem, string $direcao, int $pagina, int $porPagina): LengthAwarePaginator
    {
        $coluna = self::ORDENACAO[$ordem] ?? 'p.data_abertura';

        return $this->base($f, $local)
            ->leftJoin('formas_aquisicao as fa', 'fa.id', '=', 'p.forma_aquisicao_id')
            ->leftJoin('amparos_legais as al', 'al.id', '=', 'p.amparo_legal_id')
            ->select([
                'p.id', 'p.numero_publicacao', 'p.numero_processo_sei', 'p.numero_licitacao', 'p.ano', 'p.data_abertura',
                'uc.id as unidade_id', 'uc.nome as unidade', 'o.sigla as orgao', 's.nome as sistematica', 'fa.nome as forma_aquisicao',
                'al.descricao as amparo_legal', 'p.objeto', 'p.registro_precos', 'p.eletronico', 'p.situacao', 'p.fase_atual',
                'p.valor_estimado', 'p.valor_homologado', 'p.valor_contratado', 'p.quantidade_participantes', 'p.ficticio',
                DB::raw('(SELECT COUNT(*) FROM processos_itens x WHERE x.processo_id = p.id) AS quantidade_itens'),
            ])
            ->orderBy($coluna, $direcao === 'asc' ? 'asc' : 'desc')
            ->orderBy('p.id', 'desc')
            ->paginate($porPagina, ['*'], 'pagina', $pagina);
    }

    /** Quantidades por situação e tempo médio de conclusão (seção 11: indicadores por situação). */
    public function resumo(FiltrosAplicados $f, array $local): array
    {
        $local['situacao'] = [];
        $porSituacao = $this->base($f, $local)->groupBy('p.situacao')->selectRaw('p.situacao, COUNT(*) AS n')->pluck('n', 'situacao');
        $dias = $this->base($f, $local)->where('p.situacao', 'CONCLUIDO')->whereNotNull('p.data_homologacao')
            ->select(['p.data_abertura', 'p.data_homologacao'])->get()
            ->map(fn ($r) => (strtotime($r->data_homologacao) - strtotime($r->data_abertura)) / 86400);

        return [
            'total' => (int) $porSituacao->sum(),
            'por_situacao' => collect(self::SITUACOES)->map(fn ($s) => ['situacao' => $s, 'quantidade' => (int) ($porSituacao[$s] ?? 0)])->all(),
            'tempo_medio_conclusao_dias' => $dias->isNotEmpty() ? round($dias->avg(), 1) : null,
        ];
    }

    /** Ficha completa do processo, ou null se não existir ou estiver fora do acesso do usuário. */
    public function detalhe(int $id, ?array $orgaosPermitidos, bool $verDadosPessoais): ?array
    {
        $p = DB::table('processos as p')
            ->join('sistematicas_aquisicao as s', 's.id', '=', 'p.sistematica_id')
            ->join('unidades_compradoras as uc', 'uc.id', '=', 'p.unidade_compradora_id')
            ->join('orgaos as o', 'o.id', '=', 'uc.orgao_id')
            ->leftJoin('unidades_superiores as us', 'us.id', '=', 'o.unidade_superior_id')
            ->leftJoin('formas_aquisicao as fa', 'fa.id', '=', 'p.forma_aquisicao_id')
            ->leftJoin('amparos_legais as al', 'al.id', '=', 'p.amparo_legal_id')
            ->leftJoin('regimes_legais as rl', 'rl.id', '=', 'p.regime_legal_id')
            ->leftJoin('classificacoes_modalidades as cmod', 'cmod.id', '=', 'p.modalidade_id')
            ->leftJoin('classificacoes_natureza as cn', 'cn.id', '=', 'p.classificacao_natureza_id')
            ->where('p.id', $id)
            ->when($orgaosPermitidos !== null, fn ($q) => $q->whereIn('o.id', $orgaosPermitidos ?: [0]))
            ->select([
                'p.*', 's.codigo as sistematica_codigo', 's.nome as sistematica', 'fa.nome as forma_aquisicao',
                'al.descricao as amparo_legal', 'rl.nome as regime_legal', 'cmod.nome as modalidade', 'cn.nome as natureza',
                'uc.nome as unidade', 'o.id as orgao_id', 'o.nome as orgao', 'o.sigla as orgao_sigla', 'us.nome as unidade_superior',
            ])->first();
        if (! $p) {
            return null;
        }

        $itens = DB::table('processos_itens as pi')
            ->leftJoin('itens_catalogo as ic', 'ic.id', '=', 'pi.item_catalogo_id')
            ->leftJoin('unidades_medida as um', 'um.id', '=', 'pi.unidade_medida_id')
            ->leftJoin('fornecedores as fo', 'fo.id', '=', 'pi.fornecedor_id')
            ->where('pi.processo_id', $id)->orderBy('pi.numero_item')
            ->select(['pi.id', 'pi.numero_item', 'pi.lote', 'ic.codigo as codigo_item', 'pi.item_catalogo_id', 'pi.descricao', 'um.sigla as unidade_medida',
                'pi.quantidade_estimada', 'pi.quantidade_adjudicada', 'pi.valor_unitario_estimado', 'pi.valor_unitario_adjudicado',
                'pi.valor_total_estimado', 'pi.valor_total_adjudicado', 'pi.valor_total_homologado', 'pi.valor_total_contratado',
                'fo.id as fornecedor_id', 'fo.razao_social as fornecedor', 'fo.documento as fornecedor_documento', 'fo.tipo_pessoa as fornecedor_tipo', 'pi.situacao'])
            ->get()->map(fn ($r) => DadosPessoais::mascararLinha($r, $verDadosPessoais))->all();

        $participantes = DB::table('processos_participantes as pp')
            ->join('fornecedores as fo', 'fo.id', '=', 'pp.fornecedor_id')
            ->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')
            ->where('pp.processo_id', $id)->orderByDesc('pp.vencedor')->orderBy('fo.razao_social')
            ->select(['fo.id as fornecedor_id', 'fo.razao_social as fornecedor', 'fo.documento as fornecedor_documento', 'fo.tipo_pessoa as fornecedor_tipo',
                'pe.nome as porte', 'pp.vencedor', 'pp.habilitado', 'pp.valor_proposta'])
            ->get()->map(fn ($r) => DadosPessoais::mascararLinha($r, $verDadosPessoais))->all();

        $vencedores = collect($itens)->whereNotNull('fornecedor_id')->unique('fornecedor_id')
            ->map(fn ($i) => ['id' => $i->fornecedor_id, 'nome' => $i->fornecedor])->values()->all();

        $registradas = DB::table('processos_fases')->where('processo_id', $id)->get()->keyBy('fase');
        $fases = collect(FasesProcesso::doProcedimento($p->sistematica_codigo))->map(function ($fase) use ($registradas) {
            $r = $registradas[$fase] ?? null;

            return [
                'fase' => $fase, 'nome' => FasesProcesso::NOMES[$fase],
                'data_inicio' => $r?->data_inicio, 'data_fim' => $r?->data_fim,
                'estado' => ! $r ? 'pendente' : ($r->data_fim ? 'concluida' : 'em_andamento'),
                'observacao' => $r?->observacao,
            ];
        })->all();

        return [
            'processo' => $p,
            'vencedores' => $vencedores,
            'fases' => $fases,
            'itens' => $itens,
            'participantes' => $participantes,
            'atas' => DB::table('atas_registro_precos')->where('processo_id', $id)
                ->select(['id', 'numero', 'vigencia_inicio', 'vigencia_fim', 'situacao', 'valor_registrado'])->get(),
            'contratos' => DB::table('contratos as c')->join('fornecedores as fo', 'fo.id', '=', 'c.fornecedor_id')->where('c.processo_id', $id)
                ->select(['c.id', 'c.numero', 'fo.razao_social as fornecedor', 'c.valor_inicial', 'c.vigencia_inicio', 'c.vigencia_fim', 'c.situacao'])->get(),
            'anexos' => DB::table('arquivos_anexos')->where('referencia_tipo', 'processo')->where('referencia_id', $id)
                ->select(['id', 'nome', 'mime', 'tamanho', 'created_at'])->get(),
        ];
    }
}
