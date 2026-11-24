<?php

namespace App\Services\Importacao;

use Illuminate\Support\Facades\DB;

/**
 * Base dos importadores específicos (seção 26). Cada importador declara os campos que aceita, a chave
 * de duplicidade no arquivo, as validações de referência e como grava uma linha já convertida.
 * Gravar é idempotente: a mesma linha importada de novo atualiza o registro em vez de duplicá-lo.
 */
abstract class Importador
{
    abstract public function codigo(): string;

    abstract public function titulo(): string;

    abstract public function descricao(): string;

    /**
     * @return array<string, array{rotulo: string, tipo: string, obrigatorio?: bool, sinonimos?: list<string>, ajuda?: string}>
     */
    abstract public function campos(): array;

    /**
     * Linha de exemplo do modelo CSV (segunda linha), com dados fictícios coerentes entre os tipos:
     * o mesmo órgão, fornecedor, processo, ata e contrato aparecem em todos os modelos. Se for enviada
     * sem alterações, a linha é ignorada na importação (ver ImportacaoService::analisar).
     *
     * @return array<string, string> campo => valor como o usuário digitaria na planilha
     */
    public function exemplo(): array
    {
        return [];
    }

    /** Chave que identifica o mesmo registro dentro do arquivo (duplicidade). */
    abstract public function chave(array $l): ?string;

    /** O registro já existe no banco (a importação vai atualizá-lo)? */
    abstract public function existe(array $l, Contexto $c): bool;

    abstract public function gravar(array $l, Contexto $c): void;

    /**
     * Inconsistências que dependem do banco (referências não encontradas, regras entre campos).
     *
     * @return list<array{0: string, 1: string}> [campo, mensagem]
     */
    public function validar(array $l, Contexto $c): array
    {
        return [];
    }

    /** Recálculos depois de gravar todas as linhas (totais de processos, atas...). */
    public function finalizar(Contexto $c): void {}

    /** Colunas de rastreabilidade (origem, importação, data de atualização). */
    protected function rastreio(Contexto $c): array
    {
        return ['origem' => $c->origem, 'importacao_id' => $c->importacaoId, 'ficticio' => false, 'fonte_atualizado_em' => now(), 'updated_at' => now()];
    }

    /** Atualiza o registro que casa com $onde ou cria um novo. Campos nulos não apagam valores existentes. */
    protected function salvar(string $tabela, array $onde, array $valores, ?Contexto $c = null): int
    {
        $valores = array_filter($valores, fn ($v) => $v !== null);
        if ($c) {
            $valores += $this->rastreio($c);
        } else {
            $valores['updated_at'] = now();
        }
        $id = DB::table($tabela)->where($onde)->value('id');
        if ($id) {
            DB::table($tabela)->where('id', $id)->update($valores);

            return (int) $id;
        }

        return (int) DB::table($tabela)->insertGetId($onde + $valores + ['created_at' => now()]);
    }

    /** Erro de referência padronizado. */
    protected static function naoEncontrado(string $campo, string $o_que, mixed $valor): array
    {
        return [$campo, "$o_que não encontrado(a) no cadastro: \"$valor\". Cadastre antes ou importe as unidades/classificações."];
    }

    /** Ano, mês, trimestre e semestre a partir de uma data. */
    protected static function periodo(?string $data): array
    {
        if (! $data) {
            return [];
        }
        $m = (int) substr($data, 5, 2);

        return ['ano' => (int) substr($data, 0, 4), 'mes' => $m, 'trimestre' => intdiv($m - 1, 3) + 1, 'semestre' => $m <= 6 ? 1 : 2];
    }

    /** Valor de um conjunto fechado, aceitando o código ou o rótulo. */
    protected static function opcao(?string $v, array $opcoes): ?string
    {
        if ($v === null) {
            return null;
        }
        $k = Valor::chave($v);
        foreach ($opcoes as $codigo => $rotulo) {
            if ($k === Valor::chave($codigo) || $k === Valor::chave($rotulo)) {
                return $codigo;
            }
        }

        return null;
    }
}
