<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\DB;

/**
 * Relaciona o texto livre da pergunta aos cadastros: "material de informática" vira o grupo
 * "EQUIPAMENTOS DE INFORMÁTICA", "SESAU" vira o órgão de mesma sigla. A resposta mostra sempre
 * o que foi relacionado, para o usuário conferir.
 */
final class ResolvedorTermos
{
    private const PREFERENCIA = ['grupo' => 3, 'classe' => 2, 'item' => 1];

    /**
     * @return array{tipo: string, id: int, nome: string, filtro: string, chave: string}|null
     */
    public function classificacao(string $termo): ?array
    {
        $alvo = Texto::radicais($termo);
        if (! $alvo) {
            return null;
        }
        $candidatos = DB::table('grupos_materiais')->get(['id', 'nome'])->map(fn ($r) => ['grupo', $r->id, $r->nome])
            ->merge(DB::table('classes_materiais')->get(['id', 'nome'])->map(fn ($r) => ['classe', $r->id, $r->nome]))
            ->merge(DB::table('itens_catalogo')
                ->where(fn ($q) => collect($alvo)->each(fn ($r) => $q->orWhere('descricao_normalizada', 'like', "%$r%")))->limit(3000)->get(['id', DB::raw('COALESCE(nome_resumido, descricao) AS nome')])->map(fn ($r) => ['item', $r->id, $r->nome]));

        $melhor = null;
        foreach ($candidatos as [$tipo, $id, $nome]) {
            $radicais = Texto::radicais((string) $nome);
            if (! $radicais) {
                continue;
            }
            $comuns = count(array_intersect($alvo, $radicais));
            if ($comuns === 0) {
                continue;
            }
            // Cobertura do termo pesa mais; a cobertura do nome desempata (nome mais específico).
            $nota = $comuns / count($alvo) * 10 + $comuns / count($radicais) * 2 + self::PREFERENCIA[$tipo] * 0.1;
            if ($comuns / count($alvo) >= 0.5 && ($melhor === null || $nota > $melhor['nota'])) {
                $melhor = ['nota' => $nota, 'tipo' => $tipo, 'id' => (int) $id, 'nome' => (string) $nome];
            }
        }
        if (! $melhor) {
            return null;
        }
        $prefixo = ['grupo' => 'g', 'classe' => 'c', 'item' => 'i'][$melhor['tipo']];

        return ['tipo' => $melhor['tipo'], 'id' => $melhor['id'], 'nome' => $melhor['nome'],
            'filtro' => $melhor['tipo'] === 'item' ? 'item' : 'classe', 'chave' => "$prefixo:{$melhor['id']}"];
    }

    /**
     * Órgãos citados pela sigla (palavra em maiúsculas na pergunta, como SESAU ou SEED).
     *
     * @param  list<string>  $siglas
     * @return list<array{id: int, nome: string, chave: string}>
     */
    public function orgaos(array $siglas): array
    {
        if (! $siglas) {
            return [];
        }

        return DB::table('orgaos')->whereIn(DB::raw('UPPER(sigla)'), array_map('mb_strtoupper', $siglas))->orderBy('sigla')
            ->get(['id', 'sigla', 'nome'])
            ->map(fn ($o) => ['id' => (int) $o->id, 'sigla' => mb_strtoupper((string) $o->sigla), 'nome' => $o->sigla ? "$o->sigla · $o->nome" : $o->nome, 'chave' => "o:$o->id"])->all();
    }

    /** @return list<string> */
    public function siglas(array $orgaos): array
    {
        return array_column($orgaos, 'sigla');
    }
}
