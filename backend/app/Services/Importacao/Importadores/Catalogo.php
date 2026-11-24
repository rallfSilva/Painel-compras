<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use App\Support\Normalizador;

class Catalogo extends Importador
{
    public function codigo(): string
    {
        return 'CATALOGO';
    }

    public function titulo(): string
    {
        return 'Catálogo de itens';
    }

    public function descricao(): string
    {
        return 'Itens de materiais e serviços pelo código, com classe, unidade de fornecimento e subelemento. A descrição normalizada é gerada na gravação.';
    }

    public function campos(): array
    {
        return [
            'codigo' => ['rotulo' => 'Código do item', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['codigo_item', 'codigo_material', 'catmat', 'catser', 'item_codigo']],
            'descricao' => ['rotulo' => 'Descrição', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['descricao_item', 'item']],
            'tipo' => ['rotulo' => 'Tipo (MATERIAL ou SERVICO)', 'tipo' => 'codigo'],
            'classe' => ['rotulo' => 'Classe (código ou nome)', 'tipo' => 'texto', 'sinonimos' => ['classe_codigo', 'classe_material']],
            'unidade_medida' => ['rotulo' => 'Unidade de fornecimento (sigla)', 'tipo' => 'codigo', 'sinonimos' => ['unidade', 'unidade_fornecimento', 'un']],
            'subelemento' => ['rotulo' => 'Subelemento de despesa (código)', 'tipo' => 'texto', 'sinonimos' => ['subelemento_despesa']],
            'nome_resumido' => ['rotulo' => 'Nome resumido', 'tipo' => 'texto'],
            'especificacao' => ['rotulo' => 'Especificação', 'tipo' => 'texto'],
        ];
    }

    public function exemplo(): array
    {
        return [
            'codigo' => '150587',
            'descricao' => 'Papel A4, 75 g/m², branco, resma com 500 folhas',
            'tipo' => 'MATERIAL',
            'classe' => 'Material de expediente',
            'unidade_medida' => 'RESMA',
            'subelemento' => '3.3.90.30.16',
            'nome_resumido' => 'Papel A4',
            'especificacao' => 'Formato 210 x 297 mm, alcalino, certificação ambiental',
        ];
    }

    public function chave(array $l): ?string
    {
        return mb_strtoupper($l['codigo']);
    }

    public function existe(array $l, Contexto $c): bool
    {
        return $c->ref->itemCatalogo($l['codigo']) !== null;
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if ($l['tipo'] !== null && ! in_array($l['tipo'], ['MATERIAL', 'SERVICO'], true)) {
            $e[] = ['tipo', 'Tipo deve ser MATERIAL ou SERVICO.'];
        }
        if ($l['classe'] !== null && ! $c->ref->buscar('classes_materiais', $l['classe'])) {
            $e[] = self::naoEncontrado('classe', 'Classe', $l['classe']);
        }
        if ($l['subelemento'] !== null && ! $c->ref->buscar('subelementos_despesa', $l['subelemento'])) {
            $e[] = self::naoEncontrado('subelemento', 'Subelemento', $l['subelemento']);
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $this->salvar('itens_catalogo', ['codigo' => $l['codigo']], [
            'descricao' => $l['descricao'], 'descricao_normalizada' => Normalizador::descricao($l['descricao']), 'tipo' => $l['tipo'],
            'classe_id' => $c->ref->buscar('classes_materiais', $l['classe']), 'unidade_medida_id' => self::unidadeMedida($l['unidade_medida'], $c),
            'subelemento_despesa_id' => $c->ref->buscar('subelementos_despesa', $l['subelemento']), 'nome_resumido' => $l['nome_resumido'],
            'especificacao' => $l['especificacao'],
        ], $c);
    }

    /** Unidade de fornecimento pela sigla; cria a sigla nova (UN, KG, CX...) quando não existe. */
    public static function unidadeMedida(?string $sigla, Contexto $c): ?int
    {
        if ($sigla === null) {
            return null;
        }
        if ($id = $c->ref->buscar('unidades_medida', $sigla)) {
            return $id;
        }
        $id = (new self)->salvar('unidades_medida', ['sigla' => mb_substr($sigla, 0, 20)], ['nome' => $sigla]);
        $c->ref->lembrar('unidades_medida', [$sigla], $id);

        return $id;
    }
}
