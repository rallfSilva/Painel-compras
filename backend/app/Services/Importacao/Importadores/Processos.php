<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use Illuminate\Support\Facades\DB;

/**
 * Processos de licitação e contratação, com os itens e seus resultados. Uma linha por item; sem número
 * do item, a linha grava só o cabeçalho do processo. Os totais do processo são recalculados pelos itens.
 */
class Processos extends Importador
{
    public const SITUACOES = ['EM_ANDAMENTO' => 'Em andamento', 'CONCLUIDO' => 'Concluído', 'DESERTO' => 'Deserto', 'FRACASSADO' => 'Fracassado',
        'REVOGADO' => 'Revogado', 'ANULADO' => 'Anulado', 'SUSPENSO' => 'Suspenso', 'ADJUDICADO' => 'Adjudicado', 'HOMOLOGADO' => 'Homologado',
        'CONTRATADO' => 'Contratado', 'CANCELADO' => 'Cancelado'];

    private const MPE = ['NENHUM' => 'Nenhum', 'EXCLUSIVO' => 'Exclusivo MPE', 'COTA_RESERVADA' => 'Cota reservada'];

    public function codigo(): string
    {
        return 'PROCESSOS';
    }

    public function titulo(): string
    {
        return 'Processos de licitação e itens';
    }

    public function descricao(): string
    {
        return 'Uma linha por item do processo (ou só o processo, sem número do item). Inclui estimado, adjudicado, homologado, contratado e fornecedor vencedor.';
    }

    public function campos(): array
    {
        return [
            'numero_publicacao' => ['rotulo' => 'Nº de publicação', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['numero', 'numero_processo', 'numero_compra', 'processo']],
            'identificador' => ['rotulo' => 'Identificador na fonte (ex.: nº de controle PNCP)', 'tipo' => 'texto', 'sinonimos' => ['uid', 'numero_controle_pncp', 'id_fonte']],
            'numero_processo_sei' => ['rotulo' => 'Nº do processo SEI', 'tipo' => 'texto', 'sinonimos' => ['sei', 'processo_sei']],
            'numero_licitacao' => ['rotulo' => 'Nº da licitação', 'tipo' => 'texto'],
            'unidade' => ['rotulo' => 'Unidade compradora (código, UASG ou nome)', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['unidade_compradora', 'uasg', 'codigo_unidade']],
            'objeto' => ['rotulo' => 'Objeto', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['objeto_compra', 'descricao_objeto']],
            'data_abertura' => ['rotulo' => 'Data de abertura', 'tipo' => 'data', 'obrigatorio' => true, 'sinonimos' => ['data', 'data_abertura_proposta', 'abertura']],
            'data_publicacao' => ['rotulo' => 'Data de publicação', 'tipo' => 'data'],
            'data_homologacao' => ['rotulo' => 'Data de homologação', 'tipo' => 'data'],
            'sistematica' => ['rotulo' => 'Sistemática (código ou nome)', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['sistematica_aquisicao', 'modalidade_compra']],
            'forma' => ['rotulo' => 'Forma de aquisição', 'tipo' => 'texto', 'sinonimos' => ['forma_aquisicao']],
            'modalidade' => ['rotulo' => 'Modalidade legal', 'tipo' => 'texto'],
            'amparo' => ['rotulo' => 'Amparo legal (código)', 'tipo' => 'texto', 'sinonimos' => ['amparo_legal', 'fundamento_legal']],
            'regime' => ['rotulo' => 'Regime legal (L14133, L8666...)', 'tipo' => 'texto', 'sinonimos' => ['regime_legal', 'lei']],
            'registro_precos' => ['rotulo' => 'Registro de preços? (Sim/Não)', 'tipo' => 'booleano', 'sinonimos' => ['srp', 'rp']],
            'eletronico' => ['rotulo' => 'Eletrônico? (Sim/Não)', 'tipo' => 'booleano'],
            'situacao' => ['rotulo' => 'Situação do processo', 'tipo' => 'texto', 'sinonimos' => ['situacao_processo', 'situacao_compra']],
            'valor_estimado' => ['rotulo' => 'Valor estimado do processo (sem itens)', 'tipo' => 'decimal', 'sinonimos' => ['valor_total_estimado_processo']],
            'valor_homologado' => ['rotulo' => 'Valor homologado do processo (sem itens)', 'tipo' => 'decimal'],
            'quantidade_participantes' => ['rotulo' => 'Quantidade de participantes', 'tipo' => 'numero', 'sinonimos' => ['participantes', 'licitantes']],
            'link_pncp' => ['rotulo' => 'Link no PNCP', 'tipo' => 'texto', 'sinonimos' => ['link', 'link_sistema_origem']],
            'numero_item' => ['rotulo' => 'Nº do item', 'tipo' => 'numero', 'sinonimos' => ['item_numero', 'n_item']],
            'lote' => ['rotulo' => 'Lote', 'tipo' => 'texto'],
            'item_codigo' => ['rotulo' => 'Código do item no catálogo', 'tipo' => 'texto', 'sinonimos' => ['codigo_item', 'catmat', 'codigo_material']],
            'descricao_item' => ['rotulo' => 'Descrição do item', 'tipo' => 'texto', 'sinonimos' => ['item_descricao', 'descricao']],
            'unidade_medida' => ['rotulo' => 'Unidade de fornecimento', 'tipo' => 'codigo', 'sinonimos' => ['unidade_fornecimento', 'un']],
            'quantidade_estimada' => ['rotulo' => 'Quantidade estimada', 'tipo' => 'decimal', 'sinonimos' => ['quantidade']],
            'valor_unitario_estimado' => ['rotulo' => 'Valor unitário estimado', 'tipo' => 'decimal'],
            'valor_total_estimado' => ['rotulo' => 'Valor total estimado do item', 'tipo' => 'decimal'],
            'quantidade_adjudicada' => ['rotulo' => 'Quantidade adjudicada', 'tipo' => 'decimal'],
            'valor_unitario_adjudicado' => ['rotulo' => 'Valor unitário adjudicado', 'tipo' => 'decimal'],
            'valor_total_adjudicado' => ['rotulo' => 'Valor total adjudicado', 'tipo' => 'decimal'],
            'valor_total_homologado' => ['rotulo' => 'Valor total homologado', 'tipo' => 'decimal'],
            'valor_total_contratado' => ['rotulo' => 'Valor total contratado', 'tipo' => 'decimal'],
            'fornecedor_documento' => ['rotulo' => 'CPF/CNPJ do fornecedor vencedor', 'tipo' => 'documento', 'sinonimos' => ['cnpj_fornecedor', 'fornecedor_cnpj', 'cnpj']],
            'fornecedor_nome' => ['rotulo' => 'Nome do fornecedor vencedor', 'tipo' => 'texto', 'sinonimos' => ['fornecedor', 'razao_social']],
            'situacao_item' => ['rotulo' => 'Situação do item', 'tipo' => 'texto'],
            'beneficio_mpe' => ['rotulo' => 'Benefício MPE (Nenhum, Exclusivo, Cota reservada)', 'tipo' => 'texto'],
        ];
    }

    public function chave(array $l): ?string
    {
        return $l['numero_publicacao'].'|'.($l['numero_item'] !== null ? (int) $l['numero_item'] : '-');
    }

    public function existe(array $l, Contexto $c): bool
    {
        $unidade = $c->ref->buscar('unidades_compradoras', $l['unidade']);
        $p = DB::table('processos')->where('uid', $l['identificador'] ?? 'IMP:'.$unidade.':'.$l['numero_publicacao'])->value('id')
            ?? DB::table('processos')->where('numero_publicacao', $l['numero_publicacao'])->where('unidade_compradora_id', $unidade)->value('id');
        if (! $p || $l['numero_item'] === null) {
            return (bool) $p;
        }

        return DB::table('processos_itens')->where('processo_id', $p)->where('numero_item', (int) $l['numero_item'])->exists();
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if (! $c->ref->buscar('unidades_compradoras', $l['unidade'])) {
            $e[] = self::naoEncontrado('unidade', 'Unidade compradora', $l['unidade']);
        }
        if (! $c->ref->buscar('sistematicas_aquisicao', $l['sistematica'])) {
            $e[] = self::naoEncontrado('sistematica', 'Sistemática', $l['sistematica']);
        }
        foreach (['forma' => ['formas_aquisicao', 'Forma de aquisição'], 'modalidade' => ['classificacoes_modalidades', 'Modalidade'],
            'amparo' => ['amparos_legais', 'Amparo legal'], 'regime' => ['regimes_legais', 'Regime legal']] as $campo => [$t, $n]) {
            if ($l[$campo] !== null && ! $c->ref->buscar($t, $l[$campo])) {
                $e[] = self::naoEncontrado($campo, $n, $l[$campo]);
            }
        }
        foreach (['situacao', 'situacao_item'] as $campo) {
            if ($l[$campo] !== null && ! self::opcao($l[$campo], self::SITUACOES)) {
                $e[] = [$campo, 'Situação inválida. Use: '.implode(', ', self::SITUACOES).'.'];
            }
        }
        if ($l['beneficio_mpe'] !== null && ! self::opcao($l['beneficio_mpe'], self::MPE)) {
            $e[] = ['beneficio_mpe', 'Use Nenhum, Exclusivo ou Cota reservada.'];
        }
        if ($l['numero_item'] !== null) {
            if ($l['descricao_item'] === null && $l['item_codigo'] === null) {
                $e[] = ['descricao_item', 'Informe a descrição ou o código do item.'];
            }
            if ($l['valor_total_estimado'] === null && ($l['quantidade_estimada'] === null || $l['valor_unitario_estimado'] === null)) {
                $e[] = ['valor_total_estimado', 'Informe o valor total estimado ou a quantidade e o valor unitário estimados.'];
            }
            foreach (['valor_total_estimado', 'valor_total_adjudicado', 'valor_total_contratado', 'quantidade_estimada'] as $campo) {
                if ($l[$campo] !== null && $l[$campo] < 0) {
                    $e[] = [$campo, 'O valor não pode ser negativo.'];
                }
            }
            $temResultado = $l['valor_total_adjudicado'] !== null || $l['valor_total_contratado'] !== null;
            if ($temResultado && $l['fornecedor_documento'] === null) {
                $e[] = ['fornecedor_documento', 'Item com resultado precisa do CPF/CNPJ do fornecedor.'];
            }
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $sist = $c->ref->buscar('sistematicas_aquisicao', $l['sistematica']);
        $unidade = $c->ref->buscar('unidades_compradoras', $l['unidade']);
        $uid = $l['identificador'] ?? 'IMP:'.$unidade.':'.$l['numero_publicacao'];
        // Mesmo processo: mesmo identificador da fonte ou mesmo número de publicação na mesma unidade.
        $existente = DB::table('processos')->where('uid', $uid)->value('id')
            ?? DB::table('processos')->where('numero_publicacao', $l['numero_publicacao'])->where('unidade_compradora_id', $unidade)->value('id');
        $p = $this->salvar('processos', $existente ? ['id' => $existente] : ['uid' => $uid], [
            'numero_publicacao' => $l['numero_publicacao'], 'numero_processo_sei' => $l['numero_processo_sei'], 'numero_licitacao' => $l['numero_licitacao'],
            'unidade_compradora_id' => $unidade, 'objeto' => $l['objeto'],
            'data_abertura' => $l['data_abertura'], 'data_publicacao' => $l['data_publicacao'], 'data_homologacao' => $l['data_homologacao'],
            'sistematica_id' => $sist, 'forma_aquisicao_id' => $c->ref->buscar('formas_aquisicao', $l['forma']),
            'modalidade_id' => $c->ref->buscar('classificacoes_modalidades', $l['modalidade']), 'amparo_legal_id' => $c->ref->buscar('amparos_legais', $l['amparo']),
            'regime_legal_id' => $c->ref->buscar('regimes_legais', $l['regime']), 'registro_precos' => $l['registro_precos'], 'eletronico' => $l['eletronico'],
            'situacao' => self::opcao($l['situacao'], self::SITUACOES), 'valor_estimado' => $l['valor_estimado'], 'valor_homologado' => $l['valor_homologado'],
            'quantidade_participantes' => $l['quantidade_participantes'] !== null ? (int) $l['quantidade_participantes'] : null, 'link_pncp' => $l['link_pncp'],
        ] + self::periodo($l['data_abertura']), $c);
        $c->ref->lembrarProcesso($l['numero_publicacao'], $p);
        $c->ref->lembrarProcesso($uid, $p);
        if (DB::table('processos')->where('id', $p)->whereNull('situacao')->exists()) {
            DB::table('processos')->where('id', $p)->update(['situacao' => 'EM_ANDAMENTO']);
        }
        if ($l['numero_item'] === null) {
            return;
        }

        $catalogo = $c->ref->itemCatalogo($l['item_codigo']);
        $estimado = $l['valor_total_estimado'] ?? round($l['quantidade_estimada'] * $l['valor_unitario_estimado'], 2);
        $adjudicado = $l['valor_total_adjudicado'] ?? ($l['quantidade_adjudicada'] !== null && $l['valor_unitario_adjudicado'] !== null
            ? round($l['quantidade_adjudicada'] * $l['valor_unitario_adjudicado'], 2) : null);
        $situacao = self::opcao($l['situacao_item'], self::SITUACOES)
            ?? ($l['valor_total_contratado'] !== null ? 'CONTRATADO' : ($l['valor_total_homologado'] !== null ? 'HOMOLOGADO' : ($adjudicado !== null ? 'ADJUDICADO' : 'EM_ANDAMENTO')));
        $this->salvar('processos_itens', ['processo_id' => $p, 'numero_item' => (int) $l['numero_item']], [
            'lote' => $l['lote'], 'item_catalogo_id' => $catalogo,
            'descricao' => $l['descricao_item'] ?? DB::table('itens_catalogo')->where('id', $catalogo)->value('descricao'),
            'unidade_medida_id' => Catalogo::unidadeMedida($l['unidade_medida'], $c), 'quantidade_estimada' => $l['quantidade_estimada'],
            'valor_unitario_estimado' => $l['valor_unitario_estimado'], 'valor_total_estimado' => $estimado,
            'quantidade_adjudicada' => $l['quantidade_adjudicada'], 'valor_unitario_adjudicado' => $l['valor_unitario_adjudicado'],
            'valor_total_adjudicado' => $adjudicado, 'valor_total_homologado' => $l['valor_total_homologado'], 'valor_total_contratado' => $l['valor_total_contratado'],
            'fornecedor_id' => Fornecedores::garantir($l['fornecedor_documento'], $l['fornecedor_nome'], $c), 'situacao' => $situacao,
            'beneficio_mpe' => self::opcao($l['beneficio_mpe'], self::MPE),
        ], $c);
        $c->tocar('processo', $p);
    }

    public function finalizar(Contexto $c): void
    {
        self::recalcularTotais($c->ids('processo'));
    }

    /** Totais do processo = soma dos itens; o processo vira CONTRATADO/HOMOLOGADO conforme os resultados. */
    public static function recalcularTotais(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $lote) {
            $somas = DB::table('processos_itens')->whereIn('processo_id', $lote)->groupBy('processo_id')
                ->selectRaw('processo_id, SUM(valor_total_estimado) AS e, SUM(valor_total_adjudicado) AS a, SUM(valor_total_homologado) AS h, SUM(valor_total_contratado) AS c')->get();
            foreach ($somas as $s) {
                DB::table('processos')->where('id', $s->processo_id)->update([
                    'valor_estimado' => $s->e, 'valor_adjudicado' => $s->a, 'valor_homologado' => $s->h, 'valor_contratado' => $s->c,
                ]);
            }
        }
    }
}
