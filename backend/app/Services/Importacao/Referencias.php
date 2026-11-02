<?php

namespace App\Services\Importacao;

use Illuminate\Support\Facades\DB;

/**
 * Localiza registros de referência pelo que costuma vir nas planilhas (código, sigla, nome, CNPJ, UASG),
 * com cache durante a importação. Não presume que todas as fontes usem os mesmos campos: tenta cada
 * coluna conhecida, comparando sem acentos e sem diferença de maiúsculas.
 */
final class Referencias
{
    /** @var array<string, array<string, int>> */
    private array $mapas = [];

    /** @var array<string, int|null> */
    private array $memo = [];

    /** Tabelas pequenas, carregadas inteiras: tabela => colunas de busca. */
    private const PEQUENAS = [
        'unidades_superiores' => ['codigo', 'sigla', 'nome'],
        'orgaos' => ['codigo', 'sigla', 'nome', 'cnpj'],
        'unidades_compradoras' => ['codigo', 'uasg', 'nome'],
        'sistematicas_aquisicao' => ['codigo', 'nome'],
        'formas_aquisicao' => ['codigo', 'nome'],
        'classificacoes_modalidades' => ['codigo', 'nome'],
        'amparos_legais' => ['codigo', 'descricao'],
        'regimes_legais' => ['codigo', 'nome'],
        'grupos_materiais' => ['codigo', 'nome'],
        'classes_materiais' => ['codigo', 'nome'],
        'subelementos_despesa' => ['codigo', 'nome'],
        'elementos_despesa' => ['codigo', 'nome'],
        'naturezas_despesa' => ['codigo', 'nome'],
        'classificacoes_natureza' => ['codigo', 'nome'],
        'portes_empresariais' => ['codigo', 'nome'],
        'unidades_medida' => ['sigla', 'nome'],
    ];

    public function buscar(string $tabela, mixed $valor): ?int
    {
        if (Valor::vazio($valor)) {
            return null;
        }
        $this->mapas[$tabela] ??= $this->carregar($tabela);
        $k = Valor::chave((string) $valor);
        $id = $this->mapas[$tabela][$k] ?? null;
        if ($id === null && $tabela === 'orgaos' && strlen($dig = preg_replace('/\D/', '', (string) $valor)) === 14) {
            $id = $this->mapas[$tabela][$dig] ?? null;
        }

        return $id;
    }

    /** Registra um id recém-criado no cache. */
    public function lembrar(string $tabela, array $valores, int $id): void
    {
        $this->mapas[$tabela] ??= $this->carregar($tabela);
        foreach ($valores as $v) {
            if (! Valor::vazio($v)) {
                $this->mapas[$tabela][Valor::chave((string) $v)] ??= $id;
            }
        }
    }

    private function carregar(string $tabela): array
    {
        $cols = self::PEQUENAS[$tabela] ?? throw new \LogicException("Tabela sem busca configurada: $tabela");
        $mapa = [];
        // A ordem das colunas define a prioridade: código vence nome em caso de colisão.
        foreach (DB::table($tabela)->get(array_merge(['id'], $cols)) as $r) {
            foreach (array_reverse($cols) as $c) {
                if (! Valor::vazio($r->$c)) {
                    $mapa[$c === 'cnpj' ? preg_replace('/\D/', '', $r->$c) : Valor::chave((string) $r->$c)] = (int) $r->id;
                }
            }
        }

        return $mapa;
    }

    public function fornecedor(?string $documento): ?int
    {
        if ($documento === null) {
            return null;
        }

        return $this->memo["f:$documento"] ??= DB::table('fornecedores')->where('documento', $documento)->value('id');
    }

    public function esquecerFornecedor(string $documento, int $id): void
    {
        $this->memo["f:$documento"] = $id;
    }

    public function itemCatalogo(?string $codigo): ?int
    {
        if ($codigo === null) {
            return null;
        }

        return $this->memo["i:$codigo"] ??= DB::table('itens_catalogo')->where('codigo', $codigo)->value('id');
    }

    /** Processo pelo número de publicação, pelo identificador da fonte (uid) ou pelo número do processo SEI. */
    public function processo(?string $numero): ?int
    {
        if ($numero === null) {
            return null;
        }

        return $this->memo["p:$numero"] ??= DB::table('processos')->where('numero_publicacao', $numero)->orWhere('uid', $numero)
            ->orWhere('numero_processo_sei', $numero)->orderBy('id')->value('id');
    }

    public function lembrarProcesso(string $numero, int $id): void
    {
        $this->memo["p:$numero"] = $id;
    }

    public function contrato(?string $numero, ?int $unidade): ?int
    {
        if ($numero === null) {
            return null;
        }

        return $this->memo["c:$numero:$unidade"] ??= DB::table('contratos')->where(fn ($q) => $q->where('numero', $numero)->orWhere('uid', $numero))
            ->when($unidade, fn ($q) => $q->where('unidade_compradora_id', $unidade))->orderBy('id')->value('id');
    }

    public function ata(?string $numero, ?int $orgao): ?int
    {
        if ($numero === null) {
            return null;
        }

        return $this->memo["a:$numero:$orgao"] ??= DB::table('atas_registro_precos')->where(fn ($q) => $q->where('numero', $numero)->orWhere('uid', $numero))
            ->when($orgao, fn ($q) => $q->where('orgao_gerenciador_id', $orgao))->orderBy('id')->value('id');
    }

    /** Município pelo código IBGE (7 dígitos) ou pelo nome, com UF opcional. */
    public function municipio(mixed $valor, ?string $uf = null): ?int
    {
        if (Valor::vazio($valor)) {
            return null;
        }
        $v = trim((string) $valor);
        if (preg_match('/^\d{7}$/', $v)) {
            return $this->memo["m:$v"] ??= DB::table('municipios')->where('codigo_ibge', $v)->value('id');
        }
        $k = Valor::chave($v).':'.mb_strtoupper((string) $uf);
        if (! array_key_exists("m:$k", $this->memo)) {
            $q = DB::table('municipios as m')->join('estados as e', 'e.id', '=', 'm.estado_id')->where('e.uf', $uf ?: 'RR');
            $this->memo["m:$k"] = $q->get(['m.id', 'm.nome'])->first(fn ($m) => Valor::chave($m->nome) === Valor::chave($v))?->id;
        }

        return $this->memo["m:$k"];
    }
}
