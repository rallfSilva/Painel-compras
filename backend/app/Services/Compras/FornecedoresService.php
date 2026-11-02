<?php

namespace App\Services\Compras;

use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Support\DadosPessoais;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Módulo Fornecedores (seção 23): ranking, concentração e ficha do fornecedor.
 * Concentração é apresentada como sinalizador de necessidade de análise, nunca como irregularidade.
 */
class FornecedoresService
{
    private const ORDENACAO = [
        'razao_social' => 'fo.razao_social', 'contratado' => 'contratado', 'processos' => 'processos',
        'itens' => 'itens', 'orgaos' => 'orgaos', 'uf' => 'ef.uf', 'porte' => 'pe.nome',
    ];

    public function __construct(
        private readonly FiltroService $filtros,
        private readonly Configuracoes $config,
    ) {}

    private function agregado(FiltrosAplicados $f): Builder
    {
        return $this->filtros->baseItens($f)
            ->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')
            ->whereNotNull('pi.fornecedor_id')
            ->whereNotNull('pi.valor_total_contratado');
    }

    public function ranking(FiltrosAplicados $f, array $local, string $ordem, string $direcao, int $pagina, int $porPagina, bool $verDadosPessoais): array
    {
        $total = (float) $this->agregado($f)->sum('pi.valor_total_contratado');
        $q = $this->agregado($f);
        if (($busca = trim((string) ($local['busca'] ?? ''))) !== '') {
            $termo = '%'.mb_strtolower($busca).'%';
            $digitos = preg_replace('/\D/', '', $busca);
            $q->where(fn ($w) => $w->whereRaw('LOWER(fo.razao_social) LIKE ?', [$termo])
                ->orWhereRaw('LOWER(fo.nome_fantasia) LIKE ?', [$termo])
                ->when(strlen($digitos) >= 4, fn ($x) => $x->orWhere('fo.documento', 'like', "%$digitos%")));
        }

        $pag = $q->groupBy('fo.id')
            ->selectRaw('fo.id, MAX(fo.razao_social) AS razao_social, MAX(fo.documento) AS fornecedor_documento, MAX(fo.tipo_pessoa) AS fornecedor_tipo,
                MAX(pe.nome) AS porte, MAX(pe.grupo) AS grupo_porte, MAX(mf.nome) AS municipio, MAX(ef.uf) AS uf, MAX(fo.ficticio) AS ficticio,
                SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT p.id) AS processos, COUNT(*) AS itens,
                COUNT(DISTINCT uc.orgao_id) AS orgaos, COUNT(DISTINCT p.unidade_compradora_id) AS unidades')
            ->orderBy(self::ORDENACAO[$ordem] ?? 'contratado', $direcao === 'asc' ? 'asc' : 'desc')
            ->orderBy('fo.id')
            ->paginate($porPagina, ['*'], 'pagina', $pagina);

        $inicio = ($pag->currentPage() - 1) * $pag->perPage();
        $pag->getCollection()->transform(function ($r, $i) use ($total, $verDadosPessoais, $inicio, $ordem, $direcao) {
            $r->contratado = round((float) $r->contratado, 2);
            $r->percentual = $total > 0 ? round($r->contratado / $total * 100, 2) : null;
            $r->posicao = ($ordem === 'contratado' && $direcao !== 'asc') ? $inicio + $i + 1 : null;

            return DadosPessoais::mascararLinha($r, $verDadosPessoais);
        });

        return ['paginacao' => $pag, 'total_contratado' => round($total, 2)];
    }

    /**
     * Concentração do valor contratado: participação dos 5 e 10 maiores, índice HHI e
     * sinalizadores de unidades em que um único fornecedor supera o limite configurado.
     */
    public function concentracao(FiltrosAplicados $f): array
    {
        $porFornecedor = $this->agregado($f)->groupBy('pi.fornecedor_id')
            ->selectRaw('pi.fornecedor_id, SUM(pi.valor_total_contratado) AS v')->orderByDesc('v')->pluck('v')->map(fn ($v) => (float) $v);
        $total = $porFornecedor->sum();
        $hhi = $total > 0 ? round($porFornecedor->sum(fn ($v) => ($v / $total * 100) ** 2), 1) : null;

        $limite = (float) $this->config->int('fornecedores.limite_concentracao', 30);
        $porUnidade = $this->agregado($f)->groupBy('p.unidade_compradora_id', 'pi.fornecedor_id')
            ->selectRaw('p.unidade_compradora_id AS u, pi.fornecedor_id AS fo, SUM(pi.valor_total_contratado) AS v, COUNT(DISTINCT p.id) AS n')
            ->get()->groupBy('u');
        $nomesU = DB::table('unidades_compradoras')->whereIn('id', $porUnidade->keys())->pluck('nome', 'id');
        $sinais = [];
        foreach ($porUnidade as $u => $linhas) {
            $tot = (float) $linhas->sum('v');
            $maior = $linhas->sortByDesc('v')->first();
            // Unidades com poucos processos ou um único fornecedor não são sinalizadas: não há comparação possível.
            if ($tot <= 0 || $linhas->count() < 2 || $linhas->sum('n') < 5) {
                continue;
            }
            $pct = $maior->v / $tot * 100;
            if ($pct >= $limite) {
                $sinais[] = ['unidade_id' => (int) $u, 'unidade' => $nomesU[$u] ?? '', 'fornecedor_id' => (int) $maior->fo,
                    'percentual' => round($pct, 2), 'valor' => round((float) $maior->v, 2), 'processos' => (int) $maior->n];
            }
        }
        usort($sinais, fn ($a, $b) => $b['percentual'] <=> $a['percentual']);
        $nomesF = DB::table('fornecedores')->whereIn('id', array_column($sinais, 'fornecedor_id'))->pluck('razao_social', 'id');
        foreach ($sinais as &$s) {
            $s['fornecedor'] = $nomesF[$s['fornecedor_id']] ?? '';
        }

        return [
            'fornecedores' => $porFornecedor->count(),
            'total_contratado' => round($total, 2),
            'percentual_top5' => $total > 0 ? round($porFornecedor->take(5)->sum() / $total * 100, 2) : null,
            'percentual_top10' => $total > 0 ? round($porFornecedor->take(10)->sum() / $total * 100, 2) : null,
            'hhi' => $hhi,
            'classificacao_hhi' => $hhi === null ? null : ($hhi < 1500 ? 'BAIXA' : ($hhi < 2500 ? 'MODERADA' : 'ALTA')),
            'limite_sinalizacao' => $limite,
            'sinalizadores' => array_slice($sinais, 0, 50),
        ];
    }

    /** Ficha do fornecedor: cadastro e dados analíticos dentro dos filtros globais. */
    public function detalhe(int $id, FiltrosAplicados $f, bool $verDadosPessoais): ?array
    {
        $fo = DB::table('fornecedores as fo')
            ->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')
            ->leftJoin('municipios as m', 'm.id', '=', 'fo.municipio_id')
            ->leftJoin('estados as e', 'e.id', '=', 'm.estado_id')
            ->leftJoin('regioes_planejamento as rp', 'rp.id', '=', 'm.regiao_planejamento_id')
            ->where('fo.id', $id)
            ->select(['fo.*', 'pe.nome as porte', 'pe.grupo as grupo_porte', 'm.nome as municipio', 'e.uf', 'rp.nome as regiao'])
            ->first();
        if (! $fo) {
            return null;
        }
        if ($fo->tipo_pessoa === 'F' && ! $verDadosPessoais) {
            $fo->documento = DadosPessoais::mascararCpf($fo->documento);
            foreach (['endereco', 'cep', 'email', 'telefone'] as $campo) {
                $fo->$campo = $fo->$campo !== null ? 'Protegido (LGPD)' : null;
            }
        }
        $fo->cnaes_secundarios = $fo->cnaes_secundarios ? json_decode($fo->cnaes_secundarios, true) : [];

        $ff = $f->com('fornecedor', ["fo:$id"]);
        $base = fn () => $this->agregado($ff);

        $resumo = $base()->selectRaw('SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT p.id) AS processos_vencidos,
            COUNT(*) AS itens, COUNT(DISTINCT uc.orgao_id) AS orgaos')->first();

        $idsProcessos = $this->filtros->idsProcessos($f->com('fornecedor', []));
        $participacao = DB::table('processos_participantes')->where('fornecedor_id', $id)->whereIn('processo_id', $idsProcessos)
            ->selectRaw('COUNT(*) AS participacoes, SUM(CASE WHEN vencedor = 1 THEN 1 ELSE 0 END) AS vitorias')->first();

        return [
            'fornecedor' => $fo,
            'resumo' => [
                'contratado' => $resumo->contratado !== null ? round((float) $resumo->contratado, 2) : null,
                'processos_vencidos' => (int) $resumo->processos_vencidos,
                'itens' => (int) $resumo->itens,
                'orgaos' => (int) $resumo->orgaos,
                'participacoes' => (int) $participacao->participacoes,
                'vitorias' => (int) $participacao->vitorias,
                'taxa_vitoria' => $participacao->participacoes > 0 ? round($participacao->vitorias / $participacao->participacoes * 100, 2) : null,
            ],
            'evolucao_anual' => $base()->groupBy('p.ano')->orderBy('p.ano')
                ->selectRaw('p.ano, SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT p.id) AS processos')->get()
                ->map(fn ($r) => ['ano' => (int) $r->ano, 'contratado' => round((float) $r->contratado, 2), 'processos' => (int) $r->processos])->all(),
            'orgaos' => $base()->groupBy('o.id')
                ->selectRaw('o.id, MAX(o.sigla) AS sigla, MAX(o.nome) AS nome, SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT p.id) AS processos')
                ->orderByDesc('contratado')->limit(15)->get(),
            'itens' => $base()->groupBy('pi.item_catalogo_id')
                ->selectRaw('pi.item_catalogo_id AS id, MAX(ic.codigo) AS codigo, MAX(pi.descricao) AS descricao, SUM(pi.quantidade_adjudicada) AS quantidade,
                    SUM(pi.valor_total_contratado) AS contratado, COUNT(*) AS vezes')
                ->orderByDesc('contratado')->limit(15)->get(),
            'contratos' => DB::table('contratos as c')->join('unidades_compradoras as uc', 'uc.id', '=', 'c.unidade_compradora_id')
                ->where('c.fornecedor_id', $id)->when($f->orgaosPermitidos !== null, fn ($q) => $q->whereIn('uc.orgao_id', $f->orgaosPermitidos ?: [0]))->orderByDesc('c.vigencia_inicio')->limit(30)
                ->select(['c.id', 'c.numero', 'uc.nome as unidade', 'c.valor_inicial', 'c.vigencia_inicio', 'c.vigencia_fim', 'c.situacao'])->get(),
            'atas' => DB::table('atas_itens as ai')->join('atas_registro_precos as a', 'a.id', '=', 'ai.ata_id')
                ->join('unidades_compradoras as uc', 'uc.id', '=', 'a.orgao_gerenciador_id')
                ->where('ai.fornecedor_id', $id)->when($f->orgaosPermitidos !== null, fn ($q) => $q->whereIn('uc.orgao_id', $f->orgaosPermitidos ?: [0]))
                ->groupBy('a.id', 'a.numero', 'uc.nome', 'a.vigencia_fim', 'a.situacao')
                ->selectRaw('a.id, a.numero, uc.nome AS orgao_gerenciador, a.vigencia_fim, a.situacao, COUNT(*) AS itens,
                    SUM(ai.quantidade_registrada * ai.valor_unitario_registrado) AS valor_registrado')
                ->orderByDesc('a.vigencia_fim')->limit(30)->get(),
        ];
    }
}
