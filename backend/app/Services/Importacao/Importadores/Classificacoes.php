<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use Illuminate\Support\Facades\DB;

class Classificacoes extends Importador
{
    /** tabela lógica => [tabela física, coluna do nome, tabela do pai, coluna do pai, pai obrigatório] */
    private const TABELAS = [
        'AMPARO_LEGAL' => ['amparos_legais', 'descricao', 'regimes_legais', 'regime_legal_id', true],
        'SISTEMATICA' => ['sistematicas_aquisicao', 'nome', null, null, false],
        'FORMA_AQUISICAO' => ['formas_aquisicao', 'nome', 'sistematicas_aquisicao', 'sistematica_id', true],
        'NATUREZA_DESPESA' => ['naturezas_despesa', 'nome', null, null, false],
        'ELEMENTO_DESPESA' => ['elementos_despesa', 'nome', 'naturezas_despesa', 'natureza_despesa_id', false],
        'SUBELEMENTO_DESPESA' => ['subelementos_despesa', 'nome', 'elementos_despesa', 'elemento_despesa_id', false],
    ];

    public function codigo(): string
    {
        return 'CLASSIFICACOES';
    }

    public function titulo(): string
    {
        return 'Tabelas de classificação';
    }

    public function descricao(): string
    {
        return 'Amparos legais, sistemáticas, formas de aquisição, naturezas, elementos e subelementos de despesa. Informe a tabela em cada linha.';
    }

    public function campos(): array
    {
        return [
            'tabela' => ['rotulo' => 'Tabela ('.implode(', ', array_keys(self::TABELAS)).')', 'tipo' => 'codigo', 'obrigatorio' => true, 'sinonimos' => ['tipo', 'classificacao']],
            'codigo' => ['rotulo' => 'Código', 'tipo' => 'codigo', 'obrigatorio' => true],
            'nome' => ['rotulo' => 'Nome / descrição', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['descricao']],
            'codigo_pai' => ['rotulo' => 'Código do nível superior (regime, sistemática, natureza ou elemento)', 'tipo' => 'codigo', 'sinonimos' => ['pai', 'regime', 'sistematica', 'natureza', 'elemento']],
        ];
    }

    public function exemplo(): array
    {
        return [
            'tabela' => 'SUBELEMENTO_DESPESA',
            'codigo' => '3.3.90.30.16',
            'nome' => 'Material de expediente',
            'codigo_pai' => '3.3.90.30',
        ];
    }

    public function chave(array $l): ?string
    {
        return $l['tabela'].'|'.$l['codigo'];
    }

    public function existe(array $l, Contexto $c): bool
    {
        $t = self::TABELAS[$l['tabela']] ?? null;

        return $t && DB::table($t[0])->where('codigo', $l['codigo'])->exists();
    }

    public function validar(array $l, Contexto $c): array
    {
        $t = self::TABELAS[$l['tabela']] ?? null;
        if (! $t) {
            return [['tabela', 'Tabela inválida. Use: '.implode(', ', array_keys(self::TABELAS)).'.']];
        }
        if ($t[2] && $l['codigo_pai'] === null && $t[4]) {
            return [['codigo_pai', 'Informe o código do nível superior para '.$l['tabela'].'.']];
        }
        if ($t[2] && $l['codigo_pai'] !== null && ! $c->ref->buscar($t[2], $l['codigo_pai'])
            && ! DB::table($t[2])->where('codigo', $l['codigo_pai'])->exists()) {
            return [self::naoEncontrado('codigo_pai', 'Nível superior', $l['codigo_pai'])];
        }

        return [];
    }

    public function gravar(array $l, Contexto $c): void
    {
        [$tabela, $nome, $pai, $colPai] = self::TABELAS[$l['tabela']];
        $dados = [$nome => $l['nome']];
        if ($pai && $l['codigo_pai'] !== null) {
            $dados[$colPai] = DB::table($pai)->where('codigo', $l['codigo_pai'])->value('id');
        }
        if ($tabela === 'sistematicas_aquisicao' && ! DB::table($tabela)->where('codigo', $l['codigo'])->exists()) {
            $dados += ['tipo' => 'OUTRO', 'com_disputa' => false];
        }
        $this->salvar($tabela, ['codigo' => $l['codigo']], $dados);
    }
}
