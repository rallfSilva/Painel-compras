<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use Illuminate\Support\Facades\DB;

/** Resultados de licitações: propostas e vencedores dos itens de processos já cadastrados. */
class Resultados extends Importador
{
    public function codigo(): string
    {
        return 'RESULTADOS';
    }

    public function titulo(): string
    {
        return 'Resultados de licitações';
    }

    public function descricao(): string
    {
        return 'Uma linha por proposta de fornecedor em um item. A linha do vencedor atualiza o resultado do item (adjudicado, homologado, contratado).';
    }

    public function campos(): array
    {
        return [
            'numero_publicacao' => ['rotulo' => 'Nº de publicação do processo', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['processo', 'numero']],
            'numero_item' => ['rotulo' => 'Nº do item', 'tipo' => 'numero', 'obrigatorio' => true, 'sinonimos' => ['item']],
            'fornecedor_documento' => ['rotulo' => 'CPF/CNPJ do fornecedor', 'tipo' => 'documento', 'obrigatorio' => true, 'sinonimos' => ['cnpj', 'cpf_cnpj', 'ni_fornecedor']],
            'fornecedor_nome' => ['rotulo' => 'Nome do fornecedor', 'tipo' => 'texto', 'sinonimos' => ['fornecedor', 'razao_social']],
            'vencedor' => ['rotulo' => 'Vencedor? (Sim/Não)', 'tipo' => 'booleano'],
            'valor_proposta' => ['rotulo' => 'Valor da proposta', 'tipo' => 'decimal', 'sinonimos' => ['proposta', 'lance']],
            'habilitado' => ['rotulo' => 'Habilitado? (Sim/Não)', 'tipo' => 'booleano'],
            'quantidade_adjudicada' => ['rotulo' => 'Quantidade adjudicada', 'tipo' => 'decimal'],
            'valor_unitario_adjudicado' => ['rotulo' => 'Valor unitário adjudicado', 'tipo' => 'decimal'],
            'valor_total_adjudicado' => ['rotulo' => 'Valor total adjudicado', 'tipo' => 'decimal'],
            'valor_total_homologado' => ['rotulo' => 'Valor total homologado', 'tipo' => 'decimal'],
            'valor_total_contratado' => ['rotulo' => 'Valor total contratado', 'tipo' => 'decimal'],
            'data_adjudicacao' => ['rotulo' => 'Data de adjudicação', 'tipo' => 'data'],
            'situacao' => ['rotulo' => 'Situação do item', 'tipo' => 'texto'],
        ];
    }

    public function chave(array $l): ?string
    {
        return $l['numero_publicacao'].'|'.(int) $l['numero_item'].'|'.$l['fornecedor_documento'];
    }

    private function item(array $l, Contexto $c): ?int
    {
        $p = $c->ref->processo($l['numero_publicacao']);

        return $p ? DB::table('processos_itens')->where('processo_id', $p)->where('numero_item', (int) $l['numero_item'])->value('id') : null;
    }

    public function existe(array $l, Contexto $c): bool
    {
        $p = $c->ref->processo($l['numero_publicacao']);
        $f = $c->ref->fornecedor($l['fornecedor_documento']);

        return $p && $f && DB::table('processos_participantes')->where('processo_id', $p)->where('fornecedor_id', $f)->exists();
    }

    public function validar(array $l, Contexto $c): array
    {
        if (! $c->ref->processo($l['numero_publicacao'])) {
            return [self::naoEncontrado('numero_publicacao', 'Processo', $l['numero_publicacao'])];
        }
        if (! $this->item($l, $c)) {
            return [['numero_item', "Item {$l['numero_item']} não existe no processo {$l['numero_publicacao']}. Importe os itens antes."]];
        }
        if ($l['situacao'] !== null && ! self::opcao($l['situacao'], Processos::SITUACOES)) {
            return [['situacao', 'Situação inválida.']];
        }

        return [];
    }

    public function gravar(array $l, Contexto $c): void
    {
        $p = $c->ref->processo($l['numero_publicacao']);
        $f = Fornecedores::garantir($l['fornecedor_documento'], $l['fornecedor_nome'], $c);
        $vencedor = $l['vencedor'] ?? ($l['valor_total_adjudicado'] !== null || $l['valor_total_contratado'] !== null);
        $this->salvar('processos_participantes', ['processo_id' => $p, 'fornecedor_id' => $f], [
            'vencedor' => $vencedor ?: DB::table('processos_participantes')->where('processo_id', $p)->where('fornecedor_id', $f)->value('vencedor') ?? false,
            'valor_proposta' => $l['valor_proposta'], 'habilitado' => $l['habilitado'],
        ], $c);
        if ($vencedor) {
            $adj = $l['valor_total_adjudicado'] ?? ($l['quantidade_adjudicada'] !== null && $l['valor_unitario_adjudicado'] !== null
                ? round($l['quantidade_adjudicada'] * $l['valor_unitario_adjudicado'], 2) : null);
            $this->salvar('processos_itens', ['id' => $this->item($l, $c)], [
                'fornecedor_id' => $f, 'quantidade_adjudicada' => $l['quantidade_adjudicada'], 'valor_unitario_adjudicado' => $l['valor_unitario_adjudicado'],
                'valor_total_adjudicado' => $adj, 'valor_total_homologado' => $l['valor_total_homologado'], 'valor_total_contratado' => $l['valor_total_contratado'],
                'data_adjudicacao' => $l['data_adjudicacao'],
                'situacao' => self::opcao($l['situacao'], Processos::SITUACOES) ?? ($l['valor_total_contratado'] !== null ? 'CONTRATADO' : ($l['valor_total_homologado'] !== null ? 'HOMOLOGADO' : 'ADJUDICADO')),
            ], $c);
        }
        $c->tocar('processo', $p);
    }

    public function finalizar(Contexto $c): void
    {
        $ids = $c->ids('processo');
        Processos::recalcularTotais($ids);
        foreach ($ids as $p) {
            DB::table('processos')->where('id', $p)->update(['quantidade_participantes' => DB::table('processos_participantes')->where('processo_id', $p)->count()]);
        }
    }
}
