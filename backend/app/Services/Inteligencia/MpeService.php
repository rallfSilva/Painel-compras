<?php

namespace App\Services\Inteligencia;

use App\Services\Compras\ComprasTotaisService;
use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use Illuminate\Database\Query\Builder;

/**
 * MÓDULO COMPRAS DE MPE (seção 12).
 *
 * Categorias do porte do fornecedor:
 *  - MPE: MEI, ME e EPP (grupo MPE da tabela portes_empresariais).
 *  - Agricultura familiar: produtores familiares (PAF) e cooperativas (COOP), em categoria própria.
 *  - Demais: médio e grande porte, pessoa física.
 *  - Não informado: sem porte cadastrado.
 * Os percentuais usam como base o valor contratado dos itens com fornecedor informado.
 */
class MpeService
{
    public const CATEGORIAS = [
        'MPE' => 'MPE (MEI, ME e EPP)',
        'AF' => 'Agricultura familiar (PAF e cooperativas)',
        'DEMAIS' => 'Demais portes',
        'NI' => 'Porte não informado',
    ];

    public function __construct(
        private readonly FiltroService $filtros,
        private readonly ComprasTotaisService $compras,
    ) {}

    private function base(FiltrosAplicados $f): Builder
    {
        return $this->filtros->baseItens($f)->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id');
    }

    private static function categoria(): string
    {
        return "CASE WHEN pe.grupo = 'MPE' THEN 'MPE' WHEN ".Sql::AGRICULTURA_FAMILIAR." THEN 'AF'
            WHEN pe.id IS NULL OR pe.codigo = 'NI' THEN 'NI' ELSE 'DEMAIS' END";
    }

    public function painel(FiltrosAplicados $f): array
    {
        $res = Sql::resultado();
        $eleg = 'pi.valor_total_contratado IS NOT NULL AND pi.fornecedor_id IS NOT NULL';
        $r = $this->base($f)->selectRaw("
            SUM(CASE WHEN $eleg THEN pi.valor_total_contratado END) AS elegivel,
            SUM(CASE WHEN $eleg AND pe.grupo = 'MPE' THEN pi.valor_total_contratado END) AS valor_mpe,
            SUM(CASE WHEN $eleg AND ".Sql::AGRICULTURA_FAMILIAR." THEN pi.valor_total_contratado END) AS valor_af,
            SUM(CASE WHEN $eleg AND pe.grupo = 'MPE' AND ef.uf = 'RR' THEN pi.valor_total_contratado END) AS valor_mpe_rr,
            COUNT(DISTINCT CASE WHEN pe.grupo = 'MPE' THEN pi.fornecedor_id END) AS fornecedores_mpe,
            COUNT(DISTINCT CASE WHEN pe.grupo = 'MPE' AND ef.uf = 'RR' THEN pi.fornecedor_id END) AS fornecedores_mpe_rr,
            COUNT(DISTINCT CASE WHEN ".Sql::AGRICULTURA_FAMILIAR." THEN pi.fornecedor_id END) AS fornecedores_af,
            COUNT(DISTINCT CASE WHEN pe.grupo = 'MPE' AND $res THEN p.id END) AS processos_vencidos,
            COUNT(DISTINCT CASE WHEN $res THEN p.id END) AS processos_com_resultado,
            SUM(CASE WHEN pi.beneficio_mpe = 'EXCLUSIVO' THEN 1 ELSE 0 END) AS itens_exclusivos,
            SUM(CASE WHEN pi.beneficio_mpe = 'EXCLUSIVO' THEN pi.valor_total_contratado END) AS valor_exclusivos,
            COUNT(DISTINCT CASE WHEN pi.beneficio_mpe = 'EXCLUSIVO' THEN p.id END) AS processos_exclusivos,
            SUM(CASE WHEN pi.beneficio_mpe = 'COTA_RESERVADA' THEN 1 ELSE 0 END) AS itens_cota,
            SUM(CASE WHEN pi.beneficio_mpe = 'COTA_RESERVADA' THEN pi.valor_total_contratado END) AS valor_cota
        ")->first();

        $indicadores = [
            'fornecedores_mpe' => (int) $r->fornecedores_mpe,
            'valor_mpe' => Sql::valor($r->valor_mpe),
            'percentual_mpe' => Sql::pct($r->valor_mpe, $r->elegivel),
            'processos_vencidos_mpe' => (int) $r->processos_vencidos,
            'percentual_processos_mpe' => Sql::pct($r->processos_vencidos, $r->processos_com_resultado),
            'itens_exclusivos' => (int) $r->itens_exclusivos,
            'processos_exclusivos' => (int) $r->processos_exclusivos,
            'valor_exclusivos' => Sql::valor($r->valor_exclusivos),
            'itens_cota_reservada' => (int) $r->itens_cota,
            'valor_cota_reservada' => Sql::valor($r->valor_cota),
            'valor_agricultura_familiar' => Sql::valor($r->valor_af),
            'percentual_agricultura_familiar' => Sql::pct($r->valor_af, $r->elegivel),
            'fornecedores_agricultura_familiar' => (int) $r->fornecedores_af,
            'fornecedores_mpe_rr' => (int) $r->fornecedores_mpe_rr,
            'percentual_mpe_rr' => Sql::pct($r->valor_mpe_rr, $r->valor_mpe),
        ];

        $mpe = fn (Builder $q) => $q->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')->where('pe.grupo', 'MPE');

        return [
            'indicadores' => $indicadores,
            'por_categoria' => $this->porCategoria($f),
            'por_porte' => $this->compras->agrupar($f, 'pe.id', 'pe.nome', 'porte', 'p', 20,
                fn (Builder $q) => $q->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')),
            'por_municipio' => $this->compras->agrupar($f, 'mf.id', 'mf.nome', 'fornecedor_local', 'm', 15, $mpe),
            'por_orgao' => $this->porOrgao($f),
            'evolucao_anual' => $this->evolucao($f),
            'localizacao' => $this->localizacao($f),
            'principais_fornecedores' => $this->principaisFornecedores($f),
        ];
    }

    /** Valor contratado por categoria de porte. */
    private function porCategoria(FiltrosAplicados $f): array
    {
        $linhas = $this->base($f)->whereNotNull('pi.valor_total_contratado')->whereNotNull('pi.fornecedor_id')
            ->groupBy('categoria')
            ->selectRaw(self::categoria().' AS categoria, SUM(pi.valor_total_contratado) AS valor, COUNT(DISTINCT pi.fornecedor_id) AS fornecedores, COUNT(DISTINCT p.id) AS processos')
            ->get()->keyBy('categoria');
        $total = (float) $linhas->sum('valor');

        return collect(self::CATEGORIAS)->map(fn ($nome, $cod) => [
            'categoria' => $cod,
            'nome' => $nome,
            'contratado' => Sql::valor($linhas[$cod]->valor ?? 0),
            'fornecedores' => (int) ($linhas[$cod]->fornecedores ?? 0),
            'processos' => (int) ($linhas[$cod]->processos ?? 0),
            'percentual' => Sql::pct($linhas[$cod]->valor ?? 0, $total),
        ])->values()->all();
    }

    /** Participação de MPE no valor contratado de cada órgão. */
    private function porOrgao(FiltrosAplicados $f): array
    {
        return $this->base($f)->whereNotNull('pi.valor_total_contratado')->whereNotNull('pi.fornecedor_id')
            ->groupBy('o.id')
            ->selectRaw("o.id, MAX(COALESCE(o.sigla, o.nome)) AS nome, SUM(pi.valor_total_contratado) AS total,
                SUM(CASE WHEN pe.grupo = 'MPE' THEN pi.valor_total_contratado END) AS mpe,
                COUNT(DISTINCT CASE WHEN pe.grupo = 'MPE' THEN pi.fornecedor_id END) AS fornecedores_mpe")
            ->orderByDesc('mpe')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'nome' => $r->nome, 'contratado' => Sql::valor($r->mpe ?? 0),
                'total' => Sql::valor($r->total), 'percentual' => Sql::pct($r->mpe, $r->total),
                'fornecedores_mpe' => (int) $r->fornecedores_mpe, 'filtro' => 'unidade', 'chave' => "o:$r->id",
            ])->all();
    }

    private function evolucao(FiltrosAplicados $f): array
    {
        return $this->base($f)->whereNotNull('pi.valor_total_contratado')->whereNotNull('pi.fornecedor_id')
            ->groupBy('p.ano')->orderBy('p.ano')
            ->selectRaw("p.ano, SUM(pi.valor_total_contratado) AS total,
                SUM(CASE WHEN pe.grupo = 'MPE' THEN pi.valor_total_contratado END) AS mpe,
                SUM(CASE WHEN ".Sql::AGRICULTURA_FAMILIAR.' THEN pi.valor_total_contratado END) AS af')
            ->get()
            ->map(fn ($r) => [
                'ano' => (int) $r->ano, 'total' => Sql::valor($r->total), 'mpe' => Sql::valor($r->mpe ?? 0),
                'agricultura_familiar' => Sql::valor($r->af ?? 0),
                'percentual_mpe' => Sql::pct($r->mpe, $r->total), 'percentual_agricultura_familiar' => Sql::pct($r->af, $r->total),
            ])->all();
    }

    /** MPE de Roraima x de outros estados, e do mesmo município do comprador. */
    private function localizacao(FiltrosAplicados $f): array
    {
        $r = $this->base($f)->where('pe.grupo', 'MPE')->whereNotNull('pi.valor_total_contratado')
            ->selectRaw("SUM(pi.valor_total_contratado) AS total,
                SUM(CASE WHEN fo.municipio_id = uc.municipio_id THEN pi.valor_total_contratado END) AS mesmo_municipio,
                SUM(CASE WHEN ef.uf = 'RR' AND (fo.municipio_id IS NULL OR fo.municipio_id <> uc.municipio_id) THEN pi.valor_total_contratado END) AS outros_rr,
                SUM(CASE WHEN ef.uf IS NOT NULL AND ef.uf <> 'RR' THEN pi.valor_total_contratado END) AS outros_estados,
                SUM(CASE WHEN ef.uf IS NULL THEN pi.valor_total_contratado END) AS nao_informado")
            ->first();

        return collect([
            ['MESMO_MUNICIPIO', 'Mesmo município do comprador', $r->mesmo_municipio],
            ['RORAIMA', 'Outros municípios de Roraima', $r->outros_rr],
            ['OUTROS_ESTADOS', 'Outros estados', $r->outros_estados],
            ['NI', 'Localização não informada', $r->nao_informado],
        ])->map(fn ($l) => ['grupo' => $l[0], 'nome' => $l[1], 'contratado' => Sql::valor($l[2] ?? 0), 'percentual' => Sql::pct($l[2], $r->total)])->all();
    }

    private function principaisFornecedores(FiltrosAplicados $f): array
    {
        return $this->base($f)->where('pe.grupo', 'MPE')->whereNotNull('pi.valor_total_contratado')
            ->groupBy('fo.id')
            ->selectRaw('fo.id, MAX(fo.razao_social) AS nome, MAX(pe.nome) AS porte, MAX(mf.nome) AS municipio, MAX(ef.uf) AS uf,
                SUM(pi.valor_total_contratado) AS contratado, COUNT(DISTINCT p.id) AS processos,
                SUM(CASE WHEN pi.beneficio_mpe <> \'NENHUM\' THEN 1 ELSE 0 END) AS itens_beneficio')
            ->orderByDesc('contratado')->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'nome' => $r->nome, 'porte' => $r->porte, 'municipio' => $r->municipio, 'uf' => $r->uf,
                'local' => $r->uf === 'RR', 'contratado' => Sql::valor($r->contratado), 'processos' => (int) $r->processos,
                'itens_beneficio' => (int) $r->itens_beneficio, 'filtro' => 'fornecedor', 'chave' => "fo:$r->id",
            ])->all();
    }
}
