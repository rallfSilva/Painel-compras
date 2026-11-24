<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;

class Fornecedores extends Importador
{
    public function codigo(): string
    {
        return 'FORNECEDORES';
    }

    public function titulo(): string
    {
        return 'Fornecedores';
    }

    public function descricao(): string
    {
        return 'Cadastro de fornecedores pelo CPF/CNPJ, com porte, município e contatos. Atualiza quem já existe.';
    }

    public function campos(): array
    {
        return [
            'documento' => ['rotulo' => 'CPF/CNPJ', 'tipo' => 'documento', 'obrigatorio' => true, 'sinonimos' => ['cnpj', 'cpf', 'cpf_cnpj', 'cnpj_cpf', 'ni_fornecedor', 'documento_fornecedor']],
            'razao_social' => ['rotulo' => 'Razão social / nome', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['nome', 'razao', 'fornecedor', 'nome_razao_social']],
            'nome_fantasia' => ['rotulo' => 'Nome fantasia', 'tipo' => 'texto', 'sinonimos' => ['fantasia']],
            'porte' => ['rotulo' => 'Porte (MEI, ME, EPP, MEDIO, GRANDE...)', 'tipo' => 'texto', 'sinonimos' => ['porte_empresa', 'porte_empresarial']],
            'municipio' => ['rotulo' => 'Município (nome ou código IBGE)', 'tipo' => 'texto', 'sinonimos' => ['cidade', 'municipio_ibge', 'codigo_ibge']],
            'uf' => ['rotulo' => 'UF', 'tipo' => 'codigo', 'sinonimos' => ['estado']],
            'email' => ['rotulo' => 'E-mail', 'tipo' => 'texto'],
            'telefone' => ['rotulo' => 'Telefone', 'tipo' => 'texto', 'sinonimos' => ['fone']],
            'endereco' => ['rotulo' => 'Endereço', 'tipo' => 'texto', 'sinonimos' => ['logradouro']],
            'cep' => ['rotulo' => 'CEP', 'tipo' => 'texto'],
            'cnae_principal' => ['rotulo' => 'CNAE principal', 'tipo' => 'texto', 'sinonimos' => ['cnae']],
        ];
    }

    public function exemplo(): array
    {
        return [
            'documento' => '12.345.678/0001-95',
            'razao_social' => 'Comercial Exemplo Ltda',
            'nome_fantasia' => 'Exemplo Distribuidora',
            'porte' => 'ME',
            'municipio' => 'Boa Vista',
            'uf' => 'RR',
            'email' => 'contato@exemplo.com.br',
            'telefone' => '(95) 3621-0000',
            'endereco' => 'Av. das Flores, 100, Centro',
            'cep' => '69301-000',
            'cnae_principal' => '4761-0/03',
        ];
    }

    public function chave(array $l): ?string
    {
        return $l['documento'];
    }

    public function existe(array $l, Contexto $c): bool
    {
        return $c->ref->fornecedor($l['documento']) !== null;
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if ($l['porte'] !== null && ! $c->ref->buscar('portes_empresariais', $l['porte'])) {
            $e[] = self::naoEncontrado('porte', 'Porte', $l['porte']);
        }
        if ($l['municipio'] !== null && ! $c->ref->municipio($l['municipio'], $l['uf'])) {
            $e[] = self::naoEncontrado('municipio', 'Município', $l['municipio']);
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $id = $this->salvar('fornecedores', ['documento' => $l['documento']], [
            'tipo_pessoa' => strlen($l['documento']) === 11 ? 'F' : 'J', 'razao_social' => $l['razao_social'], 'nome_fantasia' => $l['nome_fantasia'],
            'porte_id' => $c->ref->buscar('portes_empresariais', $l['porte']), 'municipio_id' => $c->ref->municipio($l['municipio'], $l['uf']),
            'email' => $l['email'], 'telefone' => $l['telefone'], 'endereco' => $l['endereco'], 'cep' => $l['cep'], 'cnae_principal' => $l['cnae_principal'],
        ], $c);
        $c->ref->esquecerFornecedor($l['documento'], $id);
    }

    /** Usado pelos outros importadores: localiza o fornecedor ou cria um cadastro mínimo com o nome informado. */
    public static function garantir(?string $documento, ?string $nome, Contexto $c): ?int
    {
        if ($documento === null) {
            return null;
        }
        if ($id = $c->ref->fornecedor($documento)) {
            return $id;
        }
        $id = (new self)->salvar('fornecedores', ['documento' => $documento], [
            'tipo_pessoa' => strlen($documento) === 11 ? 'F' : 'J', 'razao_social' => $nome ?: "Fornecedor $documento",
        ], $c);
        $c->ref->esquecerFornecedor($documento, $id);

        return $id;
    }
}
