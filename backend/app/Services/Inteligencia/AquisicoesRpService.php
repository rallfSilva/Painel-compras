<?php

namespace App\Services\Inteligencia;

use App\Services\Configuracoes;
use App\Services\Filtros\FiltrosAplicados;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MÓDULO AQUISIÇÕES POR REGISTRO DE PREÇOS (seção 15): utilização efetiva das atas.
 *
 * Por item registrado:
 *   registrado = quantidade registrada × valor unitário registrado
 *   estimado   = quantidade registrada × valor unitário estimado
 *   comprado   = Σ valor das utilizações (atas_utilizacoes)
 *   economia efetiva = Σ quantidade utilizada × (unitário estimado − unitário registrado)
 *   % utilizado = comprado / registrado × 100
 *
 * As três tabelas (por ata, por item e por órgão participante) aceitam a seleção das outras duas:
 * selecionar uma ata filtra as tabelas de itens e órgãos, e assim por diante.
 * Os sinalizadores indicam necessidade de análise, nunca conclusão automática de irregularidade.
 */
class AquisicoesRpService
{
    public function __construct(
        private readonly LicitacoesRpService $licitacoes,
        private readonly Configuracoes $config,
    ) {}

    /**
     * Itens registrados com quantidade e valor utilizados.
     *
     * @param  array{ata_id?: ?int, item_id?: ?int, unidade_id?: ?int}  $sel
     */
    private function itens(FiltrosAplicados $f, array $sel = []): Builder
    {
        $uso = DB::table('atas_utilizacoes')
            ->when($sel['unidade_id'] ?? null, fn ($q, $u) => $q->where('unidade_compradora_id', $u))
            ->groupBy('ata_item_id')
            ->selectRaw('ata_item_id, SUM(quantidade) AS quantidade, SUM(valor_total) AS valor');

        return DB::table('atas_itens as ai')
            ->joinSub($this->licitacoes->atas($f)->select('a.*'), 'a', 'a.id', '=', 'ai.ata_id')
            ->leftJoinSub($uso, 'u', 'u.ata_item_id', '=', 'ai.id')
            ->when($sel['ata_id'] ?? null, fn ($q, $id) => $q->where('ai.ata_id', $id))
            ->when($sel['item_id'] ?? null, fn ($q, $id) => $q->where('ai.item_catalogo_id', $id))
            ->when($sel['unidade_id'] ?? null, fn ($q, $u) => $q->whereExists(fn ($w) => $w->from('atas_participantes as ap')
                ->whereColumn('ap.ata_item_id', 'ai.id')->where('ap.unidade_compradora_id', $u)));
    }

    private const SOMAS = 'SUM(ai.quantidade_registrada * ai.valor_unitario_registrado) AS registrado,
        SUM(ai.quantidade_registrada * COALESCE(ai.valor_unitario_estimado, ai.valor_unitario_registrado)) AS estimado,
        SUM(COALESCE(u.valor, 0)) AS comprado,
        SUM(COALESCE(u.quantidade, 0) * (COALESCE(ai.valor_unitario_estimado, ai.valor_unitario_registrado) - ai.valor_unitario_registrado)) AS economia_efetiva,
        SUM(CASE WHEN COALESCE(u.quantidade, 0) > ai.quantidade_registrada THEN 1 ELSE 0 END) AS itens_acima_100';

    /** @param  array{ata_id?: ?int, item_id?: ?int, unidade_id?: ?int}  $sel */
    public function painel(FiltrosAplicados $f, array $sel): array
    {
        $baixa = $this->config->int('atas.utilizacao_baixa', 30);
        $alta = $this->config->int('atas.utilizacao_alta', 80);
        $dias = $this->config->int('atas.dias_alerta_vencimento', 90);
        $hoje = $f->hoje()->toDateString();
        $limite = $f->hoje()->addDays($dias)->toDateString();

        $t = $this->itens($f)->selectRaw(self::SOMAS.', COUNT(DISTINCT ai.ata_id) AS atas, COUNT(DISTINCT ai.fornecedor_id) AS fornecedores,
            COUNT(ai.id) AS itens_registrados, COUNT(DISTINCT ai.item_catalogo_id) AS itens_catalogo, COUNT(DISTINCT a.orgao_gerenciador_id) AS gestores')->first();
        $part = DB::table('atas_participantes as ap')->whereIn('ap.ata_id', $this->licitacoes->atas($f)->select('a.id'))
            ->selectRaw("COUNT(DISTINCT CASE WHEN ap.tipo = 'PARTICIPANTE' THEN ap.unidade_compradora_id END) AS orgaos,
                COUNT(DISTINCT CASE WHEN ap.tipo = 'ADERENTE' THEN ap.unidade_compradora_id END) AS aderentes,
                SUM(CASE WHEN ap.tipo <> 'GERENCIADOR' AND ap.ata_item_id IS NOT NULL THEN 1 ELSE 0 END) AS participacoes")->first();

        $economia = (float) $t->estimado - (float) $t->registrado;

        $porAta = $this->porAta($f, $sel, $hoje, $limite, $baixa, $alta);

        return [
            'indicadores' => [
                'atas' => (int) $t->atas,
                'fornecedores' => (int) $t->fornecedores,
                'itens_registrados' => (int) $t->itens_registrados,
                'itens_catalogo' => (int) $t->itens_catalogo,
                'gestores' => (int) $t->gestores,
                'orgaos_participantes' => (int) $part->orgaos,
                'orgaos_aderentes' => (int) $part->aderentes,
                'participacoes' => (int) $part->participacoes,
                'total_estimado' => Sql::valor($t->estimado),
                'total_registrado' => Sql::valor($t->registrado),
                'total_comprado' => Sql::valor($t->comprado),
                'economia_estimada' => Sql::valor($economia),
                'percentual_economia' => Sql::pct($economia, $t->estimado),
                'economia_efetiva' => Sql::valor($t->economia_efetiva),
                'percentual_utilizado' => Sql::pct($t->comprado, $t->registrado),
            ],
            'parametros' => ['utilizacao_baixa' => $baixa, 'utilizacao_alta' => $alta, 'dias_alerta_vencimento' => $dias],
            'sinalizadores' => $this->sinalizadores($this->porAta($f, [], $hoje, $limite, $baixa, $alta)),
            'por_ata' => $porAta->values()->all(),
            'por_item' => $this->porItem($f, $sel),
            'por_orgao' => $this->porOrgao($f, $sel),
        ];
    }

    private function porAta(FiltrosAplicados $f, array $sel, string $hoje, string $limite, int $baixa, int $alta): Collection
    {
        $sel['ata_id'] = null; // a tabela de atas não é filtrada pela própria seleção

        return $this->itens($f, $sel)
            ->join('unidades_compradoras as ug', 'ug.id', '=', 'a.orgao_gerenciador_id')
            ->groupBy('a.id')
            ->selectRaw(self::SOMAS.', a.id, MAX(a.numero) AS numero, MAX(a.numero_publicacao) AS numero_publicacao, MAX(a.objeto) AS objeto,
                MAX(a.situacao) AS situacao, MAX(a.vigencia_inicio) AS vigencia_inicio, MAX(a.vigencia_fim) AS vigencia_fim, MAX(ug.nome) AS gerenciador')
            ->get()
            ->map(function ($r) use ($hoje, $limite, $baixa, $alta) {
                $pct = Sql::pct($r->comprado, $r->registrado);
                $vigente = $r->situacao === 'VIGENTE' && $r->vigencia_fim >= $hoje;
                $sinais = [];
                if ((int) $r->itens_acima_100 > 0) {
                    $sinais[] = 'ACIMA_100';
                }
                if ($vigente && $r->vigencia_fim <= $limite) {
                    $sinais[] = 'VENCIMENTO_PROXIMO';
                }
                if ($pct !== null && $pct >= $alta) {
                    $sinais[] = 'ALTA_UTILIZACAO';
                } elseif ($pct !== null && $pct < $baixa) {
                    $sinais[] = 'BAIXA_UTILIZACAO';
                }

                return [
                    'id' => (int) $r->id, 'numero' => $r->numero, 'numero_publicacao' => $r->numero_publicacao, 'objeto' => $r->objeto,
                    'gerenciador' => $r->gerenciador, 'situacao' => $vigente ? 'VIGENTE' : ($r->situacao === 'VIGENTE' ? 'EXPIRADA' : $r->situacao),
                    'vigencia_inicio' => $r->vigencia_inicio, 'validade' => $r->vigencia_fim,
                    'total_registrado' => Sql::valor($r->registrado), 'total_comprado' => Sql::valor($r->comprado),
                    'percentual_utilizado' => $pct, 'itens_acima_100' => (int) $r->itens_acima_100, 'sinalizadores' => $sinais,
                ];
            })
            // Vigentes primeiro, pelo vencimento mais próximo; depois as expiradas, das mais recentes às mais antigas.
            ->sortBy(fn ($a) => $a['situacao'] === 'VIGENTE' ? '0'.$a['validade'] : '1'.(99999999 - (int) str_replace('-', '', $a['validade'])))
            ->values();
    }

    private function porItem(FiltrosAplicados $f, array $sel): array
    {
        $sel['item_id'] = null;

        return $this->itens($f, $sel)
            ->leftJoin('itens_catalogo as ic', 'ic.id', '=', 'ai.item_catalogo_id')
            ->groupBy('ai.item_catalogo_id')
            ->selectRaw(self::SOMAS.', ai.item_catalogo_id AS id, MAX(ic.codigo) AS codigo, MAX(COALESCE(ic.nome_resumido, ic.descricao)) AS descricao,
                SUM(ai.quantidade_registrada) AS qtd_registrada, SUM(COALESCE(u.quantidade, 0)) AS qtd_utilizada, COUNT(DISTINCT ai.ata_id) AS atas')
            ->orderByDesc('registrado')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id !== null ? (int) $r->id : null, 'codigo' => $r->codigo, 'descricao' => $r->descricao ?? 'Item sem catálogo',
                'atas' => (int) $r->atas,
                'quantidade_registrada' => round((float) $r->qtd_registrada, 2), 'quantidade_utilizada' => round((float) $r->qtd_utilizada, 2),
                'quantidade_disponivel' => round((float) $r->qtd_registrada - (float) $r->qtd_utilizada, 2),
                'total_registrado' => Sql::valor($r->registrado), 'total_comprado' => Sql::valor($r->comprado),
                'percentual_utilizado' => Sql::pct($r->comprado, $r->registrado), 'itens_acima_100' => (int) $r->itens_acima_100,
            ])->all();
    }

    /**
     * Por órgão participante (participantes, aderentes e o próprio gerenciador):
     *   previsto = Σ quantidade prevista × valor unitário registrado, nos itens em que participa
     *   % utilizado da ata = valor adquirido pelo órgão / valor registrado dos itens em que participa
     *   % saldo = (previsto − adquirido) / previsto
     */
    private function porOrgao(FiltrosAplicados $f, array $sel): array
    {
        $atas = $this->licitacoes->atas($f)->select('a.id');
        $uso = DB::table('atas_utilizacoes')->groupBy('ata_item_id', 'unidade_compradora_id')
            ->selectRaw('ata_item_id, unidade_compradora_id, SUM(quantidade) AS quantidade, SUM(valor_total) AS valor');

        return DB::table('atas_participantes as ap')
            ->join('atas_itens as ai', 'ai.id', '=', 'ap.ata_item_id')
            ->join('unidades_compradoras as uc', 'uc.id', '=', 'ap.unidade_compradora_id')
            ->join('orgaos as o', 'o.id', '=', 'uc.orgao_id')
            ->leftJoinSub($uso, 'u', fn ($j) => $j->on('u.ata_item_id', '=', 'ap.ata_item_id')->on('u.unidade_compradora_id', '=', 'ap.unidade_compradora_id'))
            ->whereIn('ap.ata_id', $atas)
            ->when($sel['ata_id'] ?? null, fn ($q, $id) => $q->where('ap.ata_id', $id))
            ->when($sel['item_id'] ?? null, fn ($q, $id) => $q->where('ai.item_catalogo_id', $id))
            ->groupBy('uc.id')
            ->selectRaw("uc.id, MAX(uc.nome) AS nome, MAX(o.sigla) AS orgao,
                MAX(CASE WHEN ap.tipo = 'GERENCIADOR' THEN 1 ELSE 0 END) AS gerenciador,
                MAX(CASE WHEN ap.tipo = 'ADERENTE' THEN 1 ELSE 0 END) AS aderente,
                COUNT(DISTINCT ap.ata_id) AS atas,
                SUM(COALESCE(ap.quantidade_prevista, 0) * ai.valor_unitario_registrado) AS previsto,
                SUM(ai.quantidade_registrada * ai.valor_unitario_registrado) AS registrado,
                SUM(COALESCE(u.valor, 0)) AS adquirido,
                MAX(ap.data_adesao) AS ultima_adesao")
            ->orderByDesc('adquirido')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'nome' => $r->nome, 'orgao' => $r->orgao,
                'papel' => $r->gerenciador ? 'Gerenciador' : ($r->aderente ? 'Aderente' : 'Participante'),
                'atas' => (int) $r->atas, 'valor_previsto' => Sql::valor($r->previsto), 'valor_adquirido' => Sql::valor($r->adquirido),
                'percentual_saldo' => (float) $r->previsto > 0 ? round(((float) $r->previsto - (float) $r->adquirido) / (float) $r->previsto * 100, 2) + 0.0 : null,
                'percentual_utilizado_ata' => Sql::pct($r->adquirido, $r->registrado),
                'ultima_adesao' => $r->ultima_adesao,
            ])->all();
    }

    private function sinalizadores(Collection $atas): array
    {
        $contar = fn (string $s) => $atas->filter(fn ($a) => in_array($s, $a['sinalizadores'], true))->count();

        return [
            ['codigo' => 'ACIMA_100', 'nome' => 'Utilização acima de 100%', 'quantidade' => $contar('ACIMA_100'),
                'descricao' => 'Atas com algum item utilizado acima da quantidade registrada. Requer análise à luz dos limites de adesão e de acréscimo da Lei nº 14.133/2021 e do regulamento estadual.'],
            ['codigo' => 'VENCIMENTO_PROXIMO', 'nome' => 'Vencimento próximo', 'quantidade' => $contar('VENCIMENTO_PROXIMO'),
                'descricao' => 'Atas vigentes que vencem dentro do prazo de alerta configurado.'],
            ['codigo' => 'ALTA_UTILIZACAO', 'nome' => 'Alta utilização', 'quantidade' => $contar('ALTA_UTILIZACAO'),
                'descricao' => 'Atas com percentual utilizado igual ou acima do limite de alta utilização.'],
            ['codigo' => 'BAIXA_UTILIZACAO', 'nome' => 'Baixa utilização', 'quantidade' => $contar('BAIXA_UTILIZACAO'),
                'descricao' => 'Atas com percentual utilizado abaixo do limite de baixa utilização.'],
        ];
    }
}
