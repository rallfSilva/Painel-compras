<?php

namespace App\Services\Importacao;

use App\Services\Importacao\Importadores as I;

/** Registro dos importadores específicos, na ordem recomendada de carga. */
final class RegistroImportadores
{
    /** @var list<class-string<Importador>> */
    public const CLASSES = [
        I\Classificacoes::class, I\Unidades::class, I\Fornecedores::class, I\Catalogo::class, I\Processos::class, I\Resultados::class,
        I\Atas::class, I\Contratos::class, I\Aditivos::class, I\AtasUtilizacoes::class, I\Pca::class, I\ExecucaoFinanceira::class,
    ];

    /** @return array<string, Importador> */
    public static function todos(): array
    {
        $r = [];
        foreach (self::CLASSES as $c) {
            $i = new $c;
            $r[$i->codigo()] = $i;
        }

        return $r;
    }

    public static function obter(string $codigo): Importador
    {
        return self::todos()[$codigo] ?? abort(404, 'Tipo de importação desconhecido.');
    }

    /** Sugere o campo de cada coluna do arquivo pelo nome (ou sinônimo) normalizado. */
    public static function mapear(Importador $imp, array $colunas): array
    {
        $alvos = [];
        foreach ($imp->campos() as $nome => $def) {
            foreach (array_merge([$nome, $def['rotulo']], $def['sinonimos'] ?? []) as $s) {
                $alvos[Valor::chave($s)] ??= $nome;
            }
        }
        $mapa = [];
        $usados = [];
        foreach ($colunas as $col) {
            $k = Valor::chave($col);
            $k2 = Valor::chave(preg_replace('/^.*\./', '', $col)); // JSON aninhado: "orgaoEntidade.cnpj" -> "cnpj"
            $campo = $alvos[$k] ?? $alvos[Valor::chave(preg_replace('/(?<!^)[A-Z]/', '_$0', $col))] ?? $alvos[$k2] ?? null;
            if ($campo && ! isset($usados[$campo])) {
                $mapa[$col] = $campo;
                $usados[$campo] = true;
            }
        }

        return $mapa;
    }
}
