<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;

class Unidades extends Importador
{
    private const TIPOS = ['SECRETARIA' => 'Secretaria', 'AUTARQUIA' => 'Autarquia', 'FUNDACAO' => 'Fundação', 'EMPRESA_PUBLICA' => 'Empresa pública', 'OUTRO' => 'Outro'];

    public function codigo(): string
    {
        return 'UNIDADES';
    }

    public function titulo(): string
    {
        return 'Unidades superiores, órgãos e unidades compradoras';
    }

    public function descricao(): string
    {
        return 'Uma linha por unidade compradora, com o órgão e a unidade superior. Cria ou atualiza os três níveis.';
    }

    public function campos(): array
    {
        return [
            'unidade_superior' => ['rotulo' => 'Unidade superior (nome)', 'tipo' => 'texto', 'sinonimos' => ['unidade_superior_nome', 'poder', 'administracao']],
            'unidade_superior_codigo' => ['rotulo' => 'Código da unidade superior', 'tipo' => 'texto'],
            'orgao_codigo' => ['rotulo' => 'Código do órgão', 'tipo' => 'texto'],
            'orgao_sigla' => ['rotulo' => 'Sigla do órgão', 'tipo' => 'texto', 'sinonimos' => ['sigla']],
            'orgao_nome' => ['rotulo' => 'Nome do órgão', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['orgao', 'secretaria']],
            'orgao_tipo' => ['rotulo' => 'Tipo do órgão (Secretaria, Autarquia...)', 'tipo' => 'texto'],
            'orgao_cnpj' => ['rotulo' => 'CNPJ do órgão', 'tipo' => 'documento', 'sinonimos' => ['cnpj']],
            'unidade_codigo' => ['rotulo' => 'Código da unidade compradora', 'tipo' => 'texto', 'sinonimos' => ['codigo_unidade']],
            'uasg' => ['rotulo' => 'UASG', 'tipo' => 'texto'],
            'unidade_nome' => ['rotulo' => 'Nome da unidade compradora', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['unidade', 'unidade_compradora', 'nome_unidade']],
            'municipio' => ['rotulo' => 'Município (nome ou código IBGE)', 'tipo' => 'texto', 'sinonimos' => ['cidade']],
        ];
    }

    public function chave(array $l): ?string
    {
        return mb_strtoupper(($l['orgao_sigla'] ?? $l['orgao_nome']).'|'.($l['unidade_codigo'] ?? $l['unidade_nome']));
    }

    public function existe(array $l, Contexto $c): bool
    {
        return $c->ref->buscar('unidades_compradoras', $l['unidade_codigo'] ?? $l['uasg'] ?? $l['unidade_nome']) !== null;
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if ($l['orgao_tipo'] !== null && ! self::opcao($l['orgao_tipo'], self::TIPOS)) {
            $e[] = ['orgao_tipo', 'Tipo de órgão inválido. Use: '.implode(', ', self::TIPOS).'.'];
        }
        if ($l['municipio'] !== null && ! $c->ref->municipio($l['municipio'])) {
            $e[] = self::naoEncontrado('municipio', 'Município', $l['municipio']);
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $us = null;
        if ($l['unidade_superior'] || $l['unidade_superior_codigo']) {
            $us = $c->ref->buscar('unidades_superiores', $l['unidade_superior_codigo'] ?? $l['unidade_superior'])
                ?? $this->salvar('unidades_superiores', $l['unidade_superior_codigo'] ? ['codigo' => $l['unidade_superior_codigo']] : ['nome' => $l['unidade_superior']],
                    ['nome' => $l['unidade_superior']], $c);
            $c->ref->lembrar('unidades_superiores', [$l['unidade_superior_codigo'], $l['unidade_superior']], $us);
        }
        $orgao = $c->ref->buscar('orgaos', $l['orgao_codigo'] ?? $l['orgao_cnpj'] ?? $l['orgao_sigla'] ?? $l['orgao_nome']);
        $dados = ['unidade_superior_id' => $us, 'codigo' => $l['orgao_codigo'], 'sigla' => $l['orgao_sigla'], 'nome' => $l['orgao_nome'],
            'tipo' => self::opcao($l['orgao_tipo'], self::TIPOS), 'cnpj' => $l['orgao_cnpj']];
        $orgao = $orgao ? $this->salvar('orgaos', ['id' => $orgao], $dados, $c) : $this->salvar('orgaos', ['nome' => $l['orgao_nome']], $dados + ['tipo' => 'SECRETARIA'], $c);
        $c->ref->lembrar('orgaos', [$l['orgao_codigo'], $l['orgao_sigla'], $l['orgao_nome'], $l['orgao_cnpj']], $orgao);

        $unidade = $c->ref->buscar('unidades_compradoras', $l['unidade_codigo'] ?? $l['uasg'] ?? $l['unidade_nome']);
        $dados = ['orgao_id' => $orgao, 'codigo' => $l['unidade_codigo'], 'uasg' => $l['uasg'], 'nome' => $l['unidade_nome'], 'municipio_id' => $c->ref->municipio($l['municipio'])];
        $unidade = $this->salvar('unidades_compradoras', $unidade ? ['id' => $unidade] : ['orgao_id' => $orgao, 'nome' => $l['unidade_nome']], $dados, $c);
        $c->ref->lembrar('unidades_compradoras', [$l['unidade_codigo'], $l['uasg'], $l['unidade_nome']], $unidade);
    }
}
