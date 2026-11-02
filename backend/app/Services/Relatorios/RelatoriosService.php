<?php

namespace App\Services\Relatorios;

use App\Services\Filtros\FiltrosAplicados;
use App\Services\Filtros\FiltroService;
use App\Services\Inteligencia\AlimentosService;
use App\Services\Inteligencia\AquisicoesRpService;
use App\Services\Inteligencia\ItensAdjudicadosService;
use App\Services\Inteligencia\LicitacoesRpService;
use App\Services\Inteligencia\PrecosService;
use App\Services\Inteligencia\Sql;
use App\Services\Planejamento\ContratosService;
use App\Services\Planejamento\PcaService;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MÓDULO DE RELATÓRIOS GERENCIAIS (seção 25). Cada relatório devolve título, colunas tipadas e linhas,
 * com os filtros globais; o navegador gera Excel, CSV e PDF com cabeçalho institucional, filtros, data de
 * geração e rodapé "Desenvolvido por NEXMIND".
 *
 * Economia nos relatórios agrupados = Σ (estimado − contratado) dos itens com os dois valores;
 * % economia = economia / Σ estimado dos mesmos itens × 100.
 */
class RelatoriosService
{
    public const LIMITE = 5000;

    public const RELATORIOS = [
        'geral' => ['Relatório geral de compras', 'Processos com valores estimado, contratado e economia.'],
        'orgao' => ['Relatório por órgão', 'Totais por órgão.'],
        'fornecedor' => ['Relatório por fornecedor', 'Totais por fornecedor, com porte e UF.'],
        'modalidade' => ['Relatório por modalidade', 'Totais por sistemática e forma de aquisição.'],
        'economia' => ['Relatório de economia', 'Economia por órgão e sistemática.'],
        'mpe' => ['Relatório de MPE', 'Totais por porte do fornecedor.'],
        'municipio' => ['Relatório de compras por município', 'Totais por município da unidade compradora.'],
        'natureza' => ['Relatório por natureza da despesa', 'Totais por classificação de natureza e subelemento.'],
        'classe' => ['Relatório por classe de material', 'Totais por grupo e classe.'],
        'itens' => ['Relatório de itens adjudicados', 'Itens com resultado, com todos os campos.'],
        'atas' => ['Relatório de atas (formação)', 'Atas de registro de preços, vigência e valores.'],
        'atas_saldo' => ['Relatório de utilização e saldo de atas', 'Previsto, adquirido e saldo por órgão participante.'],
        'pca' => ['Relatório do PCA', 'Demandas do plano de contratações e execução.'],
        'precos' => ['Relatório de análise de preços', 'Estatísticas de preço por item e unidade de fornecimento.'],
        'desertos' => ['Relatório de processos desertos e fracassados', 'Processos sem resultado.'],
        'contratos' => ['Relatório de contratos', 'Contratos, vigência, aditivos e acréscimos.'],
        'amparo' => ['Relatório por amparo legal', 'Totais por amparo legal.'],
        'pnae' => ['Relatório de agricultura familiar / PNAE', 'Compras de alimentos e participação da agricultura familiar por órgão.'],
    ];

    public function __construct(
        private readonly FiltroService $filtros,
        private readonly ItensAdjudicadosService $itens,
        private readonly AquisicoesRpService $aquisicoes,
        private readonly LicitacoesRpService $licitacoes,
        private readonly PcaService $pca,
        private readonly PrecosService $precos,
        private readonly ContratosService $contratos,
    ) {}

    public function lista(): array
    {
        return collect(self::RELATORIOS)->map(fn ($r, $k) => ['codigo' => $k, 'titulo' => $r[0], 'descricao' => $r[1]])->values()->all();
    }

    /** @return array{titulo: string, colunas: list<array{id: string, titulo: string, tipo: string}>, linhas: list<array<string, mixed>>, limitado: bool} */
    public function gerar(string $codigo, FiltrosAplicados $f, bool $verDadosPessoais): array
    {
        abort_unless(isset(self::RELATORIOS[$codigo]), 404, 'Relatório não encontrado.');
        [$colunas, $linhas] = match ($codigo) {
            'geral' => $this->geral($f),
            'orgao' => $this->agrupado($f, 'o.id', 'COALESCE(o.sigla, o.nome)', 'Órgão'),
            'fornecedor' => $this->agrupado($f, 'fo.id', 'fo.razao_social', 'Fornecedor', fn (Builder $q) => $q->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id'),
                ['porte' => ['Porte', 'texto', 'MAX(pe.nome)'], 'uf' => ['UF', 'texto', 'MAX(ef.uf)']]),
            'modalidade' => $this->agrupado($f, 'COALESCE(fa.id, -s.id)', 'COALESCE(fa.nome, s.nome)', 'Forma de aquisição', fn (Builder $q) => $q->leftJoin('formas_aquisicao as fa', 'fa.id', '=', 'p.forma_aquisicao_id'),
                ['sistematica' => ['Sistemática', 'texto', 'MAX(s.nome)']]),
            'economia' => $this->agrupado($f, 'o.id', 'COALESCE(o.sigla, o.nome)', 'Órgão', null, [], true),
            'mpe' => $this->agrupado($f, 'fo.porte_id', 'pe.nome', 'Porte do fornecedor', fn (Builder $q) => $q->leftJoin('portes_empresariais as pe', 'pe.id', '=', 'fo.porte_id')),
            'municipio' => $this->agrupado($f, 'uc.municipio_id', 'mc.nome', 'Município'),
            'natureza' => $this->agrupado($f, 'pi.subelemento_despesa_id', 'sd.nome', 'Subelemento', fn (Builder $q) => $q
                ->leftJoin('subelementos_despesa as sd', 'sd.id', '=', 'pi.subelemento_despesa_id')->leftJoin('classificacoes_natureza as cn', 'cn.id', '=', 'p.classificacao_natureza_id'),
                ['codigo' => ['Código', 'texto', 'MAX(sd.codigo)'], 'natureza' => ['Natureza', 'texto', 'MAX(cn.nome)']]),
            'classe' => $this->agrupado($f, 'ic.classe_id', 'cm.nome', 'Classe', fn (Builder $q) => $q->leftJoin('grupos_materiais as gm', 'gm.id', '=', 'cm.grupo_id'),
                ['grupo' => ['Grupo', 'texto', 'MAX(gm.nome)']]),
            'amparo' => $this->agrupado($f, 'p.amparo_legal_id', 'al.descricao', 'Amparo legal', fn (Builder $q) => $q->leftJoin('amparos_legais as al', 'al.id', '=', 'p.amparo_legal_id')),
            'itens' => $this->itensAdjudicados($f, $verDadosPessoais),
            'atas' => $this->atas($f),
            'atas_saldo' => $this->atasSaldo($f),
            'pca' => $this->pca($f),
            'precos' => $this->precos($f),
            'desertos' => $this->desertos($f),
            'contratos' => $this->contratos($f, $verDadosPessoais),
            'pnae' => $this->pnae($f),
        };

        return ['codigo' => $codigo, 'titulo' => self::RELATORIOS[$codigo][0], 'colunas' => $colunas,
            'linhas' => array_slice($linhas, 0, self::LIMITE), 'limitado' => count($linhas) > self::LIMITE, 'total_linhas' => count($linhas)];
    }

    private static function col(string $id, string $titulo, string $tipo = 'texto'): array
    {
        return ['id' => $id, 'titulo' => $titulo, 'tipo' => $tipo];
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: string}>  $extras  id => [título, tipo, expressão agregada]
     */
    private function agrupado(FiltrosAplicados $f, string $colId, string $colNome, string $rotulo, ?Closure $join = null, array $extras = [], bool $foco = false): array
    {
        $q = $this->filtros->baseItens($f);
        if ($join) {
            $join($q);
        }
        $nome = str_contains($colNome, 'MAX(') ? $colNome : "MAX($colNome)";
        $res = Sql::resultado();
        $comp = 'pi.valor_total_estimado IS NOT NULL AND pi.valor_total_contratado IS NOT NULL';
        $sel = collect($extras)->map(fn ($e, $id) => "{$e[2]} AS $id")->implode(', ');
        $linhas = $q->groupByRaw($colId)
            ->selectRaw("$nome AS nome".($sel ? ", $sel" : '').",
                SUM(CASE WHEN $res THEN pi.valor_total_estimado END) AS estimado, SUM(pi.valor_total_adjudicado) AS adjudicado,
                SUM(pi.valor_total_contratado) AS contratado,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado - pi.valor_total_contratado END) AS economia,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado END) AS base_economia,
                COUNT(DISTINCT p.id) AS processos, SUM(CASE WHEN $res THEN 1 ELSE 0 END) AS itens, COUNT(DISTINCT pi.fornecedor_id) AS fornecedores")
            ->orderByDesc($foco ? 'economia' : 'contratado')->get();
        $total = (float) $linhas->sum('contratado');

        $colunas = [self::col('nome', $rotulo), ...collect($extras)->map(fn ($e, $id) => self::col($id, $e[0], $e[1]))->values()->all(),
            self::col('estimado', 'Estimado (R$)', 'moeda'), self::col('adjudicado', 'Adjudicado (R$)', 'moeda'), self::col('contratado', 'Contratado (R$)', 'moeda'),
            self::col('participacao', '% do total', 'percentual'), self::col('economia', 'Economia (R$)', 'moeda'), self::col('percentual_economia', '% economia', 'percentual'),
            self::col('processos', 'Processos', 'numero'), self::col('itens', 'Itens', 'numero'), self::col('fornecedores', 'Fornecedores', 'numero')];

        return [$colunas, $linhas->map(fn ($r) => [
            'nome' => $r->nome ?? 'Não informado', ...collect($extras)->map(fn ($e, $id) => $r->$id)->all(),
            'estimado' => Sql::valor($r->estimado ?? 0), 'adjudicado' => Sql::valor($r->adjudicado ?? 0), 'contratado' => Sql::valor($r->contratado ?? 0),
            'participacao' => Sql::pct($r->contratado, $total), 'economia' => Sql::valor($r->economia ?? 0), 'percentual_economia' => Sql::pct($r->economia, $r->base_economia),
            'processos' => (int) $r->processos, 'itens' => (int) $r->itens, 'fornecedores' => (int) $r->fornecedores,
        ])->all()];
    }

    private function geral(FiltrosAplicados $f): array
    {
        $comp = 'pi.valor_total_estimado IS NOT NULL AND pi.valor_total_contratado IS NOT NULL';
        $linhas = $this->filtros->baseItens($f)->leftJoin('formas_aquisicao as fa', 'fa.id', '=', 'p.forma_aquisicao_id')
            ->groupBy('p.id')
            ->selectRaw("MAX(p.numero_publicacao) AS numero_publicacao, MAX(p.numero_processo_sei) AS processo_sei, MAX(p.data_abertura) AS data_abertura,
                MAX(o.sigla) AS orgao, MAX(uc.nome) AS unidade, MAX(COALESCE(fa.nome, s.nome)) AS modalidade, MAX(p.objeto) AS objeto, MAX(p.situacao) AS situacao,
                SUM(pi.valor_total_estimado) AS estimado, SUM(pi.valor_total_contratado) AS contratado,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado - pi.valor_total_contratado END) AS economia,
                SUM(CASE WHEN $comp THEN pi.valor_total_estimado END) AS base, COUNT(*) AS itens")
            ->orderByDesc('data_abertura')->limit(self::LIMITE + 1)->get();

        return [[
            self::col('numero_publicacao', 'Nº publicação'), self::col('processo_sei', 'Processo SEI'), self::col('data_abertura', 'Abertura', 'data'),
            self::col('orgao', 'Órgão'), self::col('unidade', 'Unidade'), self::col('modalidade', 'Modalidade'), self::col('objeto', 'Objeto'),
            self::col('situacao', 'Situação'), self::col('itens', 'Itens', 'numero'), self::col('estimado', 'Estimado (R$)', 'moeda'),
            self::col('contratado', 'Contratado (R$)', 'moeda'), self::col('economia', 'Economia (R$)', 'moeda'), self::col('percentual_economia', '% economia', 'percentual'),
        ], $linhas->map(fn ($r) => [
            'numero_publicacao' => $r->numero_publicacao, 'processo_sei' => $r->processo_sei, 'data_abertura' => $r->data_abertura, 'orgao' => $r->orgao,
            'unidade' => $r->unidade, 'modalidade' => $r->modalidade, 'objeto' => $r->objeto, 'situacao' => $r->situacao, 'itens' => (int) $r->itens,
            'estimado' => Sql::valor($r->estimado ?? 0), 'contratado' => $r->contratado !== null ? Sql::valor($r->contratado) : null,
            'economia' => $r->economia !== null ? Sql::valor($r->economia) : null, 'percentual_economia' => Sql::pct($r->economia, $r->base),
        ])->all()];
    }

    private function itensAdjudicados(FiltrosAplicados $f, bool $ver): array
    {
        $p = $this->itens->listar($f, ['busca' => '', 'situacao' => []], 'data_abertura', 'desc', 1, self::LIMITE + 1, $ver);
        $c = [['numero_publicacao', 'Nº publicação'], ['numero_processo', 'Processo SEI'], ['data_abertura', 'Abertura', 'data'], ['orgao', 'Órgão'],
            ['unidade', 'Unidade'], ['numero_item', 'Nº item', 'numero'], ['codigo_sufixo', 'Código + sufixo'], ['descricao', 'Descrição'],
            ['unidade_fornecimento', 'Unidade'], ['sistematica', 'Sistemática'], ['quantidade_adjudicada', 'Quantidade', 'decimal'],
            ['valor_unitario_estimado', 'Unit. estimado', 'unitario'], ['valor_unitario_adjudicado', 'Unit. adjudicado', 'unitario'],
            ['valor_total_estimado', 'Total estimado (R$)', 'moeda'], ['valor_total_adjudicado', 'Total adjudicado (R$)', 'moeda'],
            ['percentual_economia', '% economia', 'percentual'], ['fornecedor', 'Fornecedor'], ['fornecedor_documento', 'CPF/CNPJ'], ['porte', 'Porte'],
            ['uf_fornecedor', 'UF'], ['data_adjudicacao', 'Adjudicação', 'data'], ['situacao', 'Situação'], ['numero_contrato', 'Contrato'], ['numero_ata', 'Ata']];

        return [array_map(fn ($x) => self::col($x[0], $x[1], $x[2] ?? 'texto'), $c), collect($p->items())->map(fn ($r) => (array) $r)->all()];
    }

    private function atas(FiltrosAplicados $f): array
    {
        $hoje = $f->hoje()->toDateString();
        $linhas = $this->licitacoes->atas($f)
            ->join('unidades_compradoras as g', 'g.id', '=', 'a.orgao_gerenciador_id')
            ->leftJoin('processos as p', 'p.id', '=', 'a.processo_id')
            ->orderBy('a.vigencia_fim')
            ->get(['a.numero', 'p.numero_publicacao', 'g.nome as gerenciador', 'a.objeto', 'a.data_assinatura', 'a.vigencia_inicio', 'a.vigencia_fim', 'a.situacao',
                'a.valor_estimado', 'a.valor_registrado',
                DB::raw('(SELECT COUNT(*) FROM atas_itens ai WHERE ai.ata_id = a.id) AS itens')]);

        return [[
            self::col('numero', 'Nº ata'), self::col('numero_publicacao', 'Processo'), self::col('gerenciador', 'Gerenciador'), self::col('objeto', 'Objeto'),
            self::col('data_assinatura', 'Assinatura', 'data'), self::col('vigencia_inicio', 'Início', 'data'), self::col('vigencia_fim', 'Fim', 'data'),
            self::col('situacao', 'Situação'), self::col('itens', 'Itens', 'numero'), self::col('valor_estimado', 'Estimado (R$)', 'moeda'),
            self::col('valor_registrado', 'Registrado (R$)', 'moeda'), self::col('economia', '% economia', 'percentual'),
        ], $linhas->map(fn ($r) => [
            'numero' => $r->numero, 'numero_publicacao' => $r->numero_publicacao, 'gerenciador' => $r->gerenciador, 'objeto' => $r->objeto,
            'data_assinatura' => $r->data_assinatura, 'vigencia_inicio' => $r->vigencia_inicio, 'vigencia_fim' => $r->vigencia_fim,
            'situacao' => $r->situacao === 'VIGENTE' && $r->vigencia_fim < $hoje ? 'EXPIRADA' : $r->situacao, 'itens' => (int) $r->itens,
            'valor_estimado' => $r->valor_estimado !== null ? (float) $r->valor_estimado : null, 'valor_registrado' => $r->valor_registrado !== null ? (float) $r->valor_registrado : null,
            'economia' => (float) $r->valor_estimado > 0 && $r->valor_registrado !== null ? Sql::pct((float) $r->valor_estimado - (float) $r->valor_registrado, $r->valor_estimado) : null,
        ])->all()];
    }

    private function atasSaldo(FiltrosAplicados $f): array
    {
        $d = $this->aquisicoes->painel($f, []);

        return [[
            self::col('nome', 'Unidade'), self::col('orgao', 'Órgão'), self::col('papel', 'Papel'), self::col('atas', 'Atas', 'numero'),
            self::col('valor_previsto', 'Previsto (R$)', 'moeda'), self::col('valor_adquirido', 'Adquirido (R$)', 'moeda'),
            self::col('percentual_saldo', '% saldo', 'percentual'), self::col('percentual_utilizado_ata', '% utilizado das atas', 'percentual'),
        ], $d['por_orgao']];
    }

    private function pca(FiltrosAplicados $f): array
    {
        $p = $this->pca->listar($f, ['busca' => '', 'situacao' => []], 'data_pretendida', 'asc', self::LIMITE + 1, 1);
        $c = [['exercicio', 'Exercício', 'numero'], ['orgao', 'Órgão'], ['unidade_requisitante', 'Unidade requisitante'], ['numero_demanda', 'Nº demanda'],
            ['codigo_contratacao', 'Código da contratação'], ['objeto', 'Objeto'], ['categoria_nome', 'Categoria'], ['classe', 'Classe'],
            ['valor_estimado', 'Valor estimado (R$)', 'moeda'], ['data_pretendida', 'Data pretendida', 'data'], ['prioridade', 'Prioridade'],
            ['situacao_execucao', 'Execução'], ['processo_relacionado', 'Processo relacionado'], ['contratado', 'Contratado (R$)', 'moeda'],
            ['percentual_executado', '% executado', 'percentual']];

        return [array_map(fn ($x) => self::col($x[0], $x[1], $x[2] ?? 'texto'), $c), collect($p->items())->map(fn ($r) => (array) $r)->all()];
    }

    private function precos(FiltrosAplicados $f): array
    {
        $c = [['codigo', 'Código'], ['descricao', 'Item'], ['unidade', 'Unidade'], ['n', 'Referências', 'numero'], ['minimo', 'Mínimo', 'unitario'],
            ['mediana', 'Mediana', 'unitario'], ['media', 'Média', 'unitario'], ['maximo', 'Máximo', 'unitario'], ['desvio', 'Desvio padrão', 'unitario'],
            ['cv', 'CV', 'percentual'], ['variacao', 'Variação mín.→máx.', 'percentual']];

        return [array_map(fn ($x) => self::col($x[0], $x[1], $x[2] ?? 'texto'), $c), $this->precos->itens($f)];
    }

    private function desertos(FiltrosAplicados $f): array
    {
        $linhas = DB::table('processos as p')->join('unidades_compradoras as uc', 'uc.id', '=', 'p.unidade_compradora_id')
            ->join('orgaos as o', 'o.id', '=', 'uc.orgao_id')->join('sistematicas_aquisicao as s', 's.id', '=', 'p.sistematica_id')
            ->whereIn('p.id', $this->filtros->idsProcessos($f))->whereIn('p.situacao', ['DESERTO', 'FRACASSADO'])
            ->orderByDesc('p.data_abertura')
            ->get(['p.numero_publicacao', 'p.numero_processo_sei', 'p.data_abertura', 'o.sigla as orgao', 'uc.nome as unidade', 's.nome as sistematica',
                'p.objeto', 'p.situacao', 'p.valor_estimado', 'p.quantidade_participantes']);

        return [[
            self::col('numero_publicacao', 'Nº publicação'), self::col('numero_processo_sei', 'Processo SEI'), self::col('data_abertura', 'Abertura', 'data'),
            self::col('orgao', 'Órgão'), self::col('unidade', 'Unidade'), self::col('sistematica', 'Sistemática'), self::col('objeto', 'Objeto'),
            self::col('situacao', 'Situação'), self::col('valor_estimado', 'Estimado (R$)', 'moeda'), self::col('quantidade_participantes', 'Participantes', 'numero'),
        ], $linhas->map(fn ($r) => (array) $r + ['valor_estimado' => $r->valor_estimado !== null ? (float) $r->valor_estimado : null])->all()];
    }

    private function contratos(FiltrosAplicados $f, bool $ver): array
    {
        $p = $this->contratos->listar($f, ['busca' => '', 'situacao' => []], null, false, 'vigencia_fim', 'asc', self::LIMITE + 1, 1, $ver);
        $c = [['numero', 'Nº contrato'], ['processo', 'Processo'], ['ata', 'Ata'], ['orgao', 'Órgão'], ['unidade', 'Unidade'], ['fornecedor', 'Fornecedor'],
            ['fornecedor_documento', 'CPF/CNPJ'], ['objeto', 'Objeto'], ['valor_inicial', 'Valor inicial (R$)', 'moeda'], ['valor_atualizado', 'Valor atualizado (R$)', 'moeda'],
            ['aditivos', 'Aditivos', 'numero'], ['percentual_acrescimo', '% acréscimo', 'percentual'], ['data_assinatura', 'Assinatura', 'data'],
            ['vigencia_inicio', 'Início', 'data'], ['vigencia_fim', 'Fim', 'data'], ['situacao', 'Situação'], ['gestor', 'Gestor'], ['fiscal', 'Fiscal']];

        return [array_map(fn ($x) => self::col($x[0], $x[1], $x[2] ?? 'texto'), $c), collect($p->items())->map(fn ($r) => (array) $r)->all()];
    }

    private function pnae(FiltrosAplicados $f): array
    {
        $af = "(s.codigo = 'CHAMADA_PNAE' OR ".Sql::AGRICULTURA_FAMILIAR.')';
        $linhas = AlimentosService::restringir($this->filtros->baseItens($f))->whereNotNull('pi.valor_total_contratado')
            ->groupBy('o.id')
            ->selectRaw("MAX(COALESCE(o.sigla, o.nome)) AS orgao, SUM(pi.valor_total_contratado) AS alimentos,
                SUM(CASE WHEN s.codigo = 'CHAMADA_PNAE' THEN pi.valor_total_contratado END) AS pnae,
                SUM(CASE WHEN $af THEN pi.valor_total_contratado END) AS af, COUNT(DISTINCT CASE WHEN $af THEN pi.fornecedor_id END) AS fornecedores_af")
            ->orderByDesc('alimentos')->get();

        return [[
            self::col('orgao', 'Órgão'), self::col('alimentos', 'Alimentos (R$)', 'moeda'), self::col('pnae', 'Chamada pública PNAE (R$)', 'moeda'),
            self::col('af', 'Agricultura familiar (R$)', 'moeda'), self::col('percentual_af', '% agricultura familiar', 'percentual'),
            self::col('fornecedores_af', 'Fornecedores da agricultura familiar', 'numero'),
        ], $linhas->map(fn ($r) => ['orgao' => $r->orgao, 'alimentos' => Sql::valor($r->alimentos), 'pnae' => Sql::valor($r->pnae ?? 0),
            'af' => Sql::valor($r->af ?? 0), 'percentual_af' => Sql::pct($r->af, $r->alimentos), 'fornecedores_af' => (int) $r->fornecedores_af])->all()];
    }
}
