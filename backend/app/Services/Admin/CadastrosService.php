<?php

namespace App\Services\Admin;

use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cadastros e classificações da Administração (seção 29), descritos por um registro único:
 * cada recurso diz a tabela, a permissão, os campos e as regras de validação. Toda gravação registra o
 * antes/depois em historico_alteracoes e a operação em logs_auditoria.
 */
class CadastrosService
{
    /**
     * Tipos de campo: texto, numero, booleano, enum (opcoes), ref (tabela e rótulo).
     *
     * @var array<string, array{titulo: string, grupo: string, tabela: string, permissao: string, ordem: string, busca: list<string>, campos: array<string, array<string, mixed>>, excluir?: bool}>
     */
    public const RECURSOS = [
        'unidades-superiores' => ['titulo' => 'Unidades superiores', 'grupo' => 'Cadastros', 'tabela' => 'unidades_superiores', 'permissao' => 'cadastros.gerenciar', 'ordem' => 'nome',
            'busca' => ['nome', 'sigla', 'codigo'], 'campos' => [
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 30, 'unico' => true],
                'sigla' => ['rotulo' => 'Sigla', 'tipo' => 'texto', 'max' => 30],
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 200, 'obrigatorio' => true],
            ]],
        'orgaos' => ['titulo' => 'Órgãos', 'grupo' => 'Cadastros', 'tabela' => 'orgaos', 'permissao' => 'cadastros.gerenciar', 'ordem' => 'nome',
            'busca' => ['nome', 'sigla', 'codigo', 'cnpj'], 'campos' => [
                'unidade_superior_id' => ['rotulo' => 'Unidade superior', 'tipo' => 'ref', 'tabela' => 'unidades_superiores', 'exibe' => 'nome'],
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 30, 'unico' => true],
                'sigla' => ['rotulo' => 'Sigla', 'tipo' => 'texto', 'max' => 30],
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 200, 'obrigatorio' => true],
                'tipo' => ['rotulo' => 'Tipo', 'tipo' => 'enum', 'opcoes' => ['SECRETARIA' => 'Secretaria (administração direta)', 'AUTARQUIA' => 'Autarquia', 'FUNDACAO' => 'Fundação', 'EMPRESA_PUBLICA' => 'Empresa pública', 'OUTRO' => 'Outro'], 'obrigatorio' => true],
                'cnpj' => ['rotulo' => 'CNPJ', 'tipo' => 'texto', 'max' => 18],
                'ativo' => ['rotulo' => 'Ativo', 'tipo' => 'booleano'],
            ]],
        'unidades-compradoras' => ['titulo' => 'Unidades compradoras', 'grupo' => 'Cadastros', 'tabela' => 'unidades_compradoras', 'permissao' => 'cadastros.gerenciar', 'ordem' => 'nome',
            'busca' => ['nome', 'codigo', 'uasg'], 'campos' => [
                'orgao_id' => ['rotulo' => 'Órgão', 'tipo' => 'ref', 'tabela' => 'orgaos', 'exibe' => 'nome', 'obrigatorio' => true],
                'municipio_id' => ['rotulo' => 'Município', 'tipo' => 'ref', 'tabela' => 'municipios', 'exibe' => 'nome', 'onde' => ['estado_id', 'RR']],
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 30],
                'uasg' => ['rotulo' => 'UASG', 'tipo' => 'texto', 'max' => 20],
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 200, 'obrigatorio' => true],
                'ativo' => ['rotulo' => 'Ativa', 'tipo' => 'booleano'],
            ]],
        'fornecedores' => ['titulo' => 'Fornecedores', 'grupo' => 'Cadastros', 'tabela' => 'fornecedores', 'permissao' => 'cadastros.gerenciar', 'ordem' => 'razao_social',
            'busca' => ['razao_social', 'nome_fantasia', 'documento'], 'excluir' => false, 'campos' => [
                'documento' => ['rotulo' => 'CPF/CNPJ (só números)', 'tipo' => 'texto', 'max' => 14, 'obrigatorio' => true, 'unico' => true, 'documento' => true],
                'tipo_pessoa' => ['rotulo' => 'Tipo de pessoa', 'tipo' => 'enum', 'opcoes' => ['J' => 'Jurídica', 'F' => 'Física'], 'obrigatorio' => true],
                'razao_social' => ['rotulo' => 'Razão social / nome', 'tipo' => 'texto', 'max' => 255, 'obrigatorio' => true],
                'nome_fantasia' => ['rotulo' => 'Nome fantasia', 'tipo' => 'texto', 'max' => 255],
                'porte_id' => ['rotulo' => 'Porte', 'tipo' => 'ref', 'tabela' => 'portes_empresariais', 'exibe' => 'nome'],
                'municipio_id' => ['rotulo' => 'Município', 'tipo' => 'ref', 'tabela' => 'municipios', 'exibe' => 'nome', 'busca' => true],
                'email' => ['rotulo' => 'E-mail', 'tipo' => 'texto', 'max' => 160],
                'telefone' => ['rotulo' => 'Telefone', 'tipo' => 'texto', 'max' => 30],
            ]],
        'regimes-legais' => ['titulo' => 'Regimes legais', 'grupo' => 'Classificações', 'tabela' => 'regimes_legais', 'permissao' => 'classificacoes.gerenciar', 'ordem' => 'nome',
            'busca' => ['nome', 'codigo'], 'campos' => [
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 40, 'obrigatorio' => true, 'unico' => true],
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 160, 'obrigatorio' => true],
            ]],
        'modalidades' => ['titulo' => 'Modalidades', 'grupo' => 'Classificações', 'tabela' => 'classificacoes_modalidades', 'permissao' => 'classificacoes.gerenciar', 'ordem' => 'nome',
            'busca' => ['nome', 'codigo'], 'campos' => [
                'regime_legal_id' => ['rotulo' => 'Regime legal', 'tipo' => 'ref', 'tabela' => 'regimes_legais', 'exibe' => 'nome'],
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 40, 'obrigatorio' => true, 'unico' => true],
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 160, 'obrigatorio' => true],
            ]],
        'sistematicas' => ['titulo' => 'Sistemáticas de aquisição', 'grupo' => 'Classificações', 'tabela' => 'sistematicas_aquisicao', 'permissao' => 'classificacoes.gerenciar', 'ordem' => 'ordem',
            'busca' => ['nome', 'codigo'], 'campos' => [
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 40, 'obrigatorio' => true, 'unico' => true],
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 160, 'obrigatorio' => true],
                'tipo' => ['rotulo' => 'Tipo', 'tipo' => 'enum', 'opcoes' => ['LICITACAO' => 'Licitação', 'CONTRATACAO_DIRETA' => 'Contratação direta', 'CHAMADA_PUBLICA' => 'Chamada pública', 'PROCEDIMENTO_AUXILIAR' => 'Procedimento auxiliar', 'OUTRO' => 'Outro'], 'obrigatorio' => true],
                'com_disputa' => ['rotulo' => 'Com disputa de preço', 'tipo' => 'booleano'],
                'ordem' => ['rotulo' => 'Ordem', 'tipo' => 'numero'],
            ]],
        'formas-aquisicao' => ['titulo' => 'Formas de aquisição', 'grupo' => 'Classificações', 'tabela' => 'formas_aquisicao', 'permissao' => 'classificacoes.gerenciar', 'ordem' => 'nome',
            'busca' => ['nome', 'codigo'], 'campos' => [
                'sistematica_id' => ['rotulo' => 'Sistemática', 'tipo' => 'ref', 'tabela' => 'sistematicas_aquisicao', 'exibe' => 'nome', 'obrigatorio' => true],
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 40, 'obrigatorio' => true, 'unico' => true],
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 160, 'obrigatorio' => true],
            ]],
        'amparos-legais' => ['titulo' => 'Amparos legais', 'grupo' => 'Classificações', 'tabela' => 'amparos_legais', 'permissao' => 'classificacoes.gerenciar', 'ordem' => 'descricao',
            'busca' => ['descricao', 'codigo'], 'campos' => [
                'regime_legal_id' => ['rotulo' => 'Regime legal', 'tipo' => 'ref', 'tabela' => 'regimes_legais', 'exibe' => 'nome', 'obrigatorio' => true],
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 60, 'obrigatorio' => true, 'unico' => true],
                'descricao' => ['rotulo' => 'Descrição', 'tipo' => 'texto', 'max' => 255, 'obrigatorio' => true],
            ]],
        'naturezas' => ['titulo' => 'Classificações por natureza', 'grupo' => 'Classificações', 'tabela' => 'classificacoes_natureza', 'permissao' => 'classificacoes.gerenciar', 'ordem' => 'ordem',
            'busca' => ['nome', 'codigo'], 'campos' => [
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 40, 'obrigatorio' => true, 'unico' => true],
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 160, 'obrigatorio' => true],
                'ordem' => ['rotulo' => 'Ordem', 'tipo' => 'numero'],
            ]],
        'subelementos' => ['titulo' => 'Subelementos de despesa', 'grupo' => 'Classificações', 'tabela' => 'subelementos_despesa', 'permissao' => 'classificacoes.gerenciar', 'ordem' => 'codigo',
            'busca' => ['nome', 'codigo'], 'campos' => [
                'elemento_despesa_id' => ['rotulo' => 'Elemento de despesa', 'tipo' => 'ref', 'tabela' => 'elementos_despesa', 'exibe' => 'nome', 'obrigatorio' => true],
                'codigo' => ['rotulo' => 'Código', 'tipo' => 'texto', 'max' => 30, 'obrigatorio' => true, 'unico' => true],
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 200, 'obrigatorio' => true],
            ]],
        'equivalencias-regimes' => ['titulo' => 'Equivalência entre regimes', 'grupo' => 'Classificações', 'tabela' => 'equivalencias_regimes', 'permissao' => 'classificacoes.gerenciar', 'ordem' => 'id',
            'busca' => ['observacao'], 'campos' => [
                'tipo' => ['rotulo' => 'Tipo', 'tipo' => 'enum', 'opcoes' => ['MODALIDADE' => 'Modalidade'], 'obrigatorio' => true],
                'origem_id' => ['rotulo' => 'Modalidade de origem (lei anterior)', 'tipo' => 'ref', 'tabela' => 'classificacoes_modalidades', 'exibe' => 'nome', 'obrigatorio' => true],
                'equivalente_id' => ['rotulo' => 'Equivalente na Lei 14.133', 'tipo' => 'ref', 'tabela' => 'classificacoes_modalidades', 'exibe' => 'nome', 'obrigatorio' => true],
                'observacao' => ['rotulo' => 'Observação', 'tipo' => 'texto', 'max' => 255],
            ]],
        'integracoes' => ['titulo' => 'Integrações', 'grupo' => 'Sistema', 'tabela' => 'integracoes', 'permissao' => 'integracoes.gerenciar', 'ordem' => 'nome',
            'busca' => ['nome', 'codigo'], 'excluir' => false, 'criar' => false, 'campos' => [
                'nome' => ['rotulo' => 'Nome', 'tipo' => 'texto', 'max' => 160, 'obrigatorio' => true],
                'ativa' => ['rotulo' => 'Ativa', 'tipo' => 'booleano'],
            ]],
        'configuracoes' => ['titulo' => 'Parâmetros e prazos de alerta', 'grupo' => 'Sistema', 'tabela' => 'configuracoes', 'permissao' => 'configuracoes.gerenciar', 'ordem' => 'chave',
            'busca' => ['chave', 'descricao'], 'excluir' => false, 'criar' => false, 'somente_leitura' => ['chave', 'descricao'], 'campos' => [
                'chave' => ['rotulo' => 'Parâmetro', 'tipo' => 'texto', 'max' => 120],
                'descricao' => ['rotulo' => 'Descrição', 'tipo' => 'texto', 'max' => 255],
                'valor' => ['rotulo' => 'Valor', 'tipo' => 'texto', 'max' => 500, 'obrigatorio' => true],
            ]],
    ];

    /** Recursos que o usuário pode gerenciar, com a definição dos campos. */
    public function recursos(callable $pode): array
    {
        return collect(self::RECURSOS)->filter(fn ($r) => $pode($r['permissao']))
            ->map(fn ($r, $k) => [
                'recurso' => $k, 'titulo' => $r['titulo'], 'grupo' => $r['grupo'], 'criar' => $r['criar'] ?? true, 'excluir' => $r['excluir'] ?? true,
                'somente_leitura' => $r['somente_leitura'] ?? [],
                'campos' => collect($r['campos'])->map(fn ($c, $n) => ['nome' => $n, 'rotulo' => $c['rotulo'], 'tipo' => $c['tipo'],
                    'obrigatorio' => $c['obrigatorio'] ?? false, 'opcoes' => isset($c['opcoes']) ? collect($c['opcoes'])->map(fn ($v, $k) => ['valor' => $k, 'rotulo' => $v])->values()->all() : null])->values()->all(),
            ])->values()->all();
    }

    public static function definicao(string $recurso): array
    {
        abort_unless(isset(self::RECURSOS[$recurso]), 404, 'Cadastro não encontrado.');

        return self::RECURSOS[$recurso];
    }

    /** Opções das referências (até 2.000 por campo; municípios só de RR quando indicado). */
    public function opcoes(string $recurso): array
    {
        $def = self::definicao($recurso);
        $r = [];
        foreach ($def['campos'] as $n => $c) {
            if ($c['tipo'] !== 'ref') {
                continue;
            }
            $q = DB::table($c['tabela'])->orderBy($c['exibe'])->limit(2000);
            if (($c['onde'] ?? null) && $c['onde'][1] === 'RR') {
                $q->where('estado_id', DB::table('estados')->where('uf', 'RR')->value('id'));
            }
            $r[$n] = $q->get(['id', $c['exibe'].' as rotulo'])->map(fn ($x) => ['valor' => (int) $x->id, 'rotulo' => $x->rotulo])->all();
        }

        return $r;
    }

    public function listar(string $recurso, string $busca, int $porPagina, int $pagina): LengthAwarePaginator
    {
        $def = self::definicao($recurso);
        $q = DB::table($def['tabela'].' as t')->select('t.*');
        foreach ($def['campos'] as $n => $c) {
            if ($c['tipo'] === 'ref') {
                $q->leftJoin($c['tabela']." as r_$n", "r_$n.id", '=', "t.$n")->addSelect("r_$n.{$c['exibe']} as {$n}_rotulo");
            }
        }
        if ($busca !== '') {
            $t = '%'.mb_strtolower($busca).'%';
            $q->where(fn ($w) => collect($def['busca'])->each(fn ($col) => $w->orWhereRaw("LOWER(t.$col) LIKE ?", [$t])));
        }

        return $q->orderBy('t.'.$def['ordem'])->orderBy('t.id')->paginate($porPagina, ['*'], 'pagina', $pagina);
    }

    /** @return array<string, list<mixed>> */
    private function regras(array $def, ?int $id): array
    {
        $regras = [];
        foreach ($def['campos'] as $n => $c) {
            if (in_array($n, $def['somente_leitura'] ?? [], true)) {
                continue;
            }
            $r = [($c['obrigatorio'] ?? false) ? 'required' : 'nullable'];
            $r = [...$r, ...match ($c['tipo']) {
                'texto' => ['string', 'max:'.($c['max'] ?? 255)],
                'numero' => ['integer'],
                'booleano' => ['boolean'],
                'enum' => [Rule::in(array_keys($c['opcoes']))],
                'ref' => ['integer', Rule::exists($c['tabela'], 'id')],
            }];
            if ($c['unico'] ?? false) {
                $r[] = Rule::unique($def['tabela'], $n)->ignore($id);
            }
            if ($c['documento'] ?? false) {
                $r[] = 'regex:/^(\d{11}|\d{14})$/';
            }
            $regras[$n] = $r;
        }

        return $regras;
    }

    public function salvar(string $recurso, ?int $id, array $entrada, int $userId): int
    {
        $def = self::definicao($recurso);
        if ($id === null && ! ($def['criar'] ?? true)) {
            abort(405, 'Este cadastro não permite inclusão.');
        }
        $dados = validator($entrada, $this->regras($def, $id))->validate();
        foreach ($def['campos'] as $n => $c) {
            if ($c['tipo'] === 'booleano' && array_key_exists($n, $dados)) {
                $dados[$n] = (bool) $dados[$n];
            }
        }
        $tabela = $def['tabela'];

        return DB::transaction(function () use ($tabela, $id, $dados, $userId, $recurso) {
            $antes = $id ? DB::table($tabela)->where('id', $id)->first() : null;
            abort_if($id && ! $antes, 404, 'Registro não encontrado.');
            if ($id) {
                DB::table($tabela)->where('id', $id)->update($dados + ['updated_at' => now()]);
            } else {
                $id = DB::table($tabela)->insertGetId($dados + ['created_at' => now(), 'updated_at' => now()]);
            }
            $this->registrar($tabela, $id, $antes, DB::table($tabela)->where('id', $id)->first(), $userId, $antes ? "$recurso.editar" : "$recurso.criar");

            return $id;
        });
    }

    public function excluir(string $recurso, int $id, int $userId): void
    {
        $def = self::definicao($recurso);
        abort_unless($def['excluir'] ?? true, 405, 'Este cadastro não permite exclusão.');
        $antes = DB::table($def['tabela'])->where('id', $id)->first();
        abort_if(! $antes, 404, 'Registro não encontrado.');
        try {
            DB::table($def['tabela'])->where('id', $id)->delete();
        } catch (QueryException) {
            throw ValidationException::withMessages(['id' => 'O registro está em uso e não pode ser excluído. Desative-o ou ajuste os vínculos.']);
        }
        $this->registrar($def['tabela'], $id, $antes, null, $userId, "$recurso.excluir");
    }

    public static function registrar(string $tabela, int $id, ?object $antes, ?object $depois, int $userId, string $acao): void
    {
        $json = fn ($x) => $x ? json_encode($x, JSON_UNESCAPED_UNICODE) : null;
        DB::table('historico_alteracoes')->insert(['tabela' => $tabela, 'registro_id' => $id, 'user_id' => $userId,
            'antes' => $json($antes), 'depois' => $json($depois), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('logs_auditoria')->insert(['user_id' => $userId, 'acao' => $acao, 'recurso' => $tabela, 'recurso_id' => $id,
            'ip' => request()?->ip(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
