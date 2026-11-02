<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use Illuminate\Support\Facades\DB;

/** Utilização (aquisições) das atas pelos órgãos gerenciador, participantes e aderentes. */
class AtasUtilizacoes extends Importador
{
    public function codigo(): string
    {
        return 'ATAS_UTILIZACOES';
    }

    public function titulo(): string
    {
        return 'Utilização das atas';
    }

    public function descricao(): string
    {
        return 'Cada aquisição feita em uma ata: unidade compradora, item, quantidade, valor e data. Base do saldo e do % utilizado.';
    }

    public function campos(): array
    {
        return [
            'ata' => ['rotulo' => 'Nº da ata', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['numero_ata']],
            'orgao_gerenciador' => ['rotulo' => 'Órgão gerenciador da ata', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['gerenciador']],
            'item_codigo' => ['rotulo' => 'Código do item', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['codigo_item', 'item']],
            'fornecedor_documento' => ['rotulo' => 'CPF/CNPJ do fornecedor (quando o item tem mais de um)', 'tipo' => 'documento', 'sinonimos' => ['cnpj']],
            'unidade' => ['rotulo' => 'Unidade compradora que adquiriu', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['unidade_compradora', 'uasg']],
            'quantidade' => ['rotulo' => 'Quantidade', 'tipo' => 'decimal', 'obrigatorio' => true],
            'valor_total' => ['rotulo' => 'Valor total (padrão: quantidade × preço registrado)', 'tipo' => 'decimal', 'sinonimos' => ['valor']],
            'data' => ['rotulo' => 'Data da aquisição', 'tipo' => 'data', 'obrigatorio' => true, 'sinonimos' => ['data_aquisicao', 'data_utilizacao']],
            'contrato' => ['rotulo' => 'Nº do contrato ou empenho', 'tipo' => 'texto', 'sinonimos' => ['numero_contrato']],
        ];
    }

    public function chave(array $l): ?string
    {
        return mb_strtoupper(implode('|', [$l['orgao_gerenciador'], $l['ata'], $l['item_codigo'], $l['unidade'], $l['data'], $l['quantidade'], $l['contrato']]));
    }

    private function ataItem(array $l, Contexto $c): ?object
    {
        $ata = $c->ref->ata($l['ata'], $c->ref->buscar('orgaos', $l['orgao_gerenciador']));

        return $ata ? DB::table('atas_itens')->where('ata_id', $ata)->where('item_catalogo_id', $c->ref->itemCatalogo($l['item_codigo']))
            ->when($l['fornecedor_documento'], fn ($q, $d) => $q->where('fornecedor_id', $c->ref->fornecedor($d)))->first(['id', 'valor_unitario_registrado']) : null;
    }

    public function existe(array $l, Contexto $c): bool
    {
        $ai = $this->ataItem($l, $c);

        return $ai && DB::table('atas_utilizacoes')->where('ata_item_id', $ai->id)->where('unidade_compradora_id', $c->ref->buscar('unidades_compradoras', $l['unidade']))
            ->where('data', $l['data'])->where('quantidade', $l['quantidade'])->exists();
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if (! $c->ref->ata($l['ata'], $c->ref->buscar('orgaos', $l['orgao_gerenciador']))) {
            $e[] = self::naoEncontrado('ata', 'Ata', $l['ata']);
        } elseif (! $this->ataItem($l, $c)) {
            $e[] = ['item_codigo', "O item {$l['item_codigo']} não está registrado na ata {$l['ata']}."];
        }
        if (! $c->ref->buscar('unidades_compradoras', $l['unidade'])) {
            $e[] = self::naoEncontrado('unidade', 'Unidade compradora', $l['unidade']);
        }
        if ($l['quantidade'] !== null && $l['quantidade'] <= 0) {
            $e[] = ['quantidade', 'A quantidade deve ser maior que zero.'];
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $ai = $this->ataItem($l, $c);
        $u = $c->ref->buscar('unidades_compradoras', $l['unidade']);
        $this->salvar('atas_utilizacoes', ['ata_item_id' => $ai->id, 'unidade_compradora_id' => $u, 'data' => $l['data'], 'quantidade' => $l['quantidade']], [
            'valor_total' => $l['valor_total'] ?? round($l['quantidade'] * (float) $ai->valor_unitario_registrado, 2),
            'contrato_id' => $c->ref->contrato($l['contrato'], $u),
        ], $c);
    }
}
