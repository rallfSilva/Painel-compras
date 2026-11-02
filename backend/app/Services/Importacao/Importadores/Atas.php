<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use Illuminate\Support\Facades\DB;

/** Atas de registro de preços: uma linha por item registrado (fornecedor, quantidade e preço). */
class Atas extends Importador
{
    private const SITUACOES = ['VIGENTE' => 'Vigente', 'EXPIRADA' => 'Expirada', 'CANCELADA' => 'Cancelada', 'SUSPENSA' => 'Suspensa'];

    public function codigo(): string
    {
        return 'ATAS';
    }

    public function titulo(): string
    {
        return 'Atas de registro de preços';
    }

    public function descricao(): string
    {
        return 'Uma linha por item registrado na ata, com o órgão gerenciador, a vigência, o fornecedor e os preços.';
    }

    public function campos(): array
    {
        return [
            'numero' => ['rotulo' => 'Nº da ata', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['numero_ata', 'ata']],
            'identificador' => ['rotulo' => 'Identificador na fonte', 'tipo' => 'texto', 'sinonimos' => ['uid', 'numero_controle_pncp']],
            'orgao_gerenciador' => ['rotulo' => 'Órgão gerenciador (sigla, código ou nome)', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['orgao', 'gerenciador']],
            'numero_publicacao' => ['rotulo' => 'Nº de publicação do processo', 'tipo' => 'texto', 'sinonimos' => ['processo']],
            'objeto' => ['rotulo' => 'Objeto', 'tipo' => 'texto'],
            'data_assinatura' => ['rotulo' => 'Data de assinatura', 'tipo' => 'data'],
            'vigencia_inicio' => ['rotulo' => 'Início da vigência', 'tipo' => 'data', 'obrigatorio' => true],
            'vigencia_fim' => ['rotulo' => 'Fim da vigência', 'tipo' => 'data', 'obrigatorio' => true],
            'situacao' => ['rotulo' => 'Situação (Vigente, Expirada, Cancelada, Suspensa)', 'tipo' => 'texto'],
            'item_codigo' => ['rotulo' => 'Código do item no catálogo', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['codigo_item', 'item']],
            'fornecedor_documento' => ['rotulo' => 'CPF/CNPJ do fornecedor', 'tipo' => 'documento', 'obrigatorio' => true, 'sinonimos' => ['cnpj']],
            'fornecedor_nome' => ['rotulo' => 'Nome do fornecedor', 'tipo' => 'texto', 'sinonimos' => ['fornecedor']],
            'quantidade_registrada' => ['rotulo' => 'Quantidade registrada', 'tipo' => 'decimal', 'obrigatorio' => true, 'sinonimos' => ['quantidade']],
            'valor_unitario_estimado' => ['rotulo' => 'Valor unitário estimado', 'tipo' => 'decimal'],
            'valor_unitario_registrado' => ['rotulo' => 'Valor unitário registrado', 'tipo' => 'decimal', 'obrigatorio' => true, 'sinonimos' => ['valor_unitario']],
        ];
    }

    public function chave(array $l): ?string
    {
        return mb_strtoupper($l['orgao_gerenciador'].'|'.$l['numero'].'|'.$l['item_codigo'].'|'.$l['fornecedor_documento']);
    }

    public function existe(array $l, Contexto $c): bool
    {
        $ata = $c->ref->ata($l['identificador'] ?? $l['numero'], $c->ref->buscar('orgaos', $l['orgao_gerenciador']));

        return $ata && DB::table('atas_itens')->where('ata_id', $ata)->where('item_catalogo_id', $c->ref->itemCatalogo($l['item_codigo']))
            ->where('fornecedor_id', $c->ref->fornecedor($l['fornecedor_documento']))->exists();
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if (! $c->ref->buscar('orgaos', $l['orgao_gerenciador'])) {
            $e[] = self::naoEncontrado('orgao_gerenciador', 'Órgão', $l['orgao_gerenciador']);
        }
        if (! $c->ref->itemCatalogo($l['item_codigo'])) {
            $e[] = self::naoEncontrado('item_codigo', 'Item do catálogo', $l['item_codigo']);
        }
        if ($l['situacao'] !== null && ! self::opcao($l['situacao'], self::SITUACOES)) {
            $e[] = ['situacao', 'Situação inválida. Use: '.implode(', ', self::SITUACOES).'.'];
        }
        if ($l['vigencia_inicio'] && $l['vigencia_fim'] && $l['vigencia_fim'] < $l['vigencia_inicio']) {
            $e[] = ['vigencia_fim', 'O fim da vigência é anterior ao início.'];
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $orgao = $c->ref->buscar('orgaos', $l['orgao_gerenciador']);
        $existente = $c->ref->ata($l['identificador'] ?? $l['numero'], $orgao);
        $ata = $this->salvar('atas_registro_precos', $existente ? ['id' => $existente] : ['uid' => $l['identificador'] ?? "IMP:ATA:$orgao:{$l['numero']}"], [
            'numero' => $l['numero'], 'orgao_gerenciador_id' => $orgao, 'processo_id' => $c->ref->processo($l['numero_publicacao']), 'objeto' => $l['objeto'],
            'data_assinatura' => $l['data_assinatura'], 'vigencia_inicio' => $l['vigencia_inicio'], 'vigencia_fim' => $l['vigencia_fim'],
            'situacao' => self::opcao($l['situacao'], self::SITUACOES) ?? ($l['vigencia_fim'] < now()->toDateString() ? 'EXPIRADA' : 'VIGENTE'),
        ], $c);
        $item = $c->ref->itemCatalogo($l['item_codigo']);
        $forn = Fornecedores::garantir($l['fornecedor_documento'], $l['fornecedor_nome'], $c);
        $onde = ['ata_id' => $ata, 'item_catalogo_id' => $item, 'fornecedor_id' => $forn];
        $valores = ['quantidade_registrada' => $l['quantidade_registrada'], 'valor_unitario_estimado' => $l['valor_unitario_estimado'],
            'valor_unitario_registrado' => $l['valor_unitario_registrado'], 'updated_at' => now()];
        $valores = array_filter($valores, fn ($v) => $v !== null);
        if ($id = DB::table('atas_itens')->where($onde)->value('id')) {
            DB::table('atas_itens')->where('id', $id)->update($valores);
        } else {
            DB::table('atas_itens')->insert($onde + $valores + ['created_at' => now()]);
        }
        $c->tocar('ata', $ata);
    }

    public function finalizar(Contexto $c): void
    {
        foreach ($c->ids('ata') as $ata) {
            $s = DB::table('atas_itens')->where('ata_id', $ata)
                ->selectRaw('SUM(quantidade_registrada * valor_unitario_registrado) AS r, SUM(quantidade_registrada * COALESCE(valor_unitario_estimado, valor_unitario_registrado)) AS e')->first();
            DB::table('atas_registro_precos')->where('id', $ata)->update(['valor_registrado' => round((float) $s->r, 2), 'valor_estimado' => round((float) $s->e, 2)]);
        }
    }
}
