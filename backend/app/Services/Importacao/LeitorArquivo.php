<?php

namespace App\Services\Importacao;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

/**
 * Leitura dos arquivos de importação: XLSX, XLS, CSV e JSON. Devolve os nomes das colunas e as linhas
 * como arrays associativos (coluna => valor). A primeira linha da planilha ou do CSV é o cabeçalho.
 */
final class LeitorArquivo
{
    public const FORMATOS = ['xlsx', 'xls', 'csv', 'json'];

    public const LIMITE_LINHAS = 50000;

    /** @return array{colunas: list<string>, linhas: list<array<string, mixed>>} */
    public static function ler(string $caminho, string $formato): array
    {
        [$colunas, $linhas] = match ($formato) {
            'csv' => self::csv($caminho),
            'json' => self::json($caminho),
            'xlsx', 'xls' => self::planilha($caminho, $formato),
            default => throw new \InvalidArgumentException('Formato não suportado. Use XLSX, XLS, CSV ou JSON.'),
        };
        $colunas = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $colunas), fn ($c) => $c !== ''));
        if ($colunas === []) {
            throw new \InvalidArgumentException('O arquivo não tem cabeçalho: a primeira linha deve trazer os nomes das colunas.');
        }
        if (count($linhas) > self::LIMITE_LINHAS) {
            throw new \InvalidArgumentException('O arquivo tem mais de '.number_format(self::LIMITE_LINHAS, 0, ',', '.').' linhas. Divida-o em partes menores.');
        }

        return ['colunas' => $colunas, 'linhas' => $linhas];
    }

    /** @return array{0: list<string>, 1: list<array<string, mixed>>} */
    private static function csv(string $caminho): array
    {
        $conteudo = (string) file_get_contents($caminho);
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $primeira = strtok($conteudo, "\n") ?: '';
        $sep = collect([';', ',', "\t", '|'])->sortByDesc(fn ($s) => substr_count($primeira, $s))->first();

        $h = fopen('php://temp', 'r+');
        fwrite($h, $conteudo);
        rewind($h);
        $cab = fgetcsv($h, null, $sep, '"', '');
        $cab = array_map(fn ($c) => trim((string) $c), $cab ?: []);
        $linhas = [];
        while (($l = fgetcsv($h, null, $sep, '"', '')) !== false) {
            if ($l === [null] || count(array_filter($l, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $linhas[] = self::combinar($cab, $l);
            if (count($linhas) > self::LIMITE_LINHAS) {
                break;
            }
        }
        fclose($h);

        return [$cab, $linhas];
    }

    /** JSON: lista de objetos, ou objeto com a lista em "dados", "data", "registros" ou "items". */
    private static function json(string $caminho): array
    {
        $d = json_decode((string) file_get_contents($caminho), true);
        if (! is_array($d)) {
            throw new \InvalidArgumentException('JSON inválido: '.json_last_error_msg());
        }
        if (! array_is_list($d)) {
            $d = $d['dados'] ?? $d['data'] ?? $d['registros'] ?? $d['items'] ?? null;
            if (! is_array($d) || ! array_is_list($d)) {
                throw new \InvalidArgumentException('O JSON deve ser uma lista de objetos (ou ter a lista em "dados").');
            }
        }
        $cab = [];
        $linhas = [];
        foreach ($d as $r) {
            if (! is_array($r)) {
                continue;
            }
            $plano = self::achatar($r);
            $cab += array_fill_keys(array_keys($plano), true);
            $linhas[] = $plano;
        }

        return [array_keys($cab), $linhas];
    }

    /** Objetos aninhados viram colunas "pai.filho". */
    public static function achatar(array $r, string $prefixo = ''): array
    {
        $saida = [];
        foreach ($r as $k => $v) {
            $nome = $prefixo === '' ? (string) $k : "$prefixo.$k";
            if (is_array($v) && ! array_is_list($v)) {
                $saida += self::achatar($v, $nome);
            } elseif (is_array($v)) {
                $saida[$nome] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } else {
                $saida[$nome] = $v;
            }
        }

        return $saida;
    }

    private static function planilha(string $caminho, string $formato): array
    {
        /** @var IReader $leitor */
        $leitor = IOFactory::createReader($formato === 'xls' ? 'Xls' : 'Xlsx');
        $leitor->setReadDataOnly(true);
        $leitor->setReadEmptyCells(false);
        $folha = $leitor->load($caminho)->getSheet(0);
        if ($folha->getHighestDataRow() > self::LIMITE_LINHAS + 1) {
            throw new \InvalidArgumentException('O arquivo tem mais de '.number_format(self::LIMITE_LINHAS, 0, ',', '.').' linhas. Divida-o em partes menores.');
        }
        $dados = $folha->toArray(null, true, false, false);
        $cab = array_map(fn ($c) => trim((string) $c), array_shift($dados) ?? []);
        $linhas = [];
        foreach ($dados as $l) {
            if (count(array_filter($l, fn ($v) => $v !== null && trim((string) $v) !== '')) === 0) {
                continue;
            }
            $linhas[] = self::combinar($cab, $l);
        }

        return [$cab, $linhas];
    }

    private static function combinar(array $cab, array $l): array
    {
        $r = [];
        foreach ($cab as $i => $c) {
            if ($c !== '') {
                $r[$c] = $l[$i] ?? null;
            }
        }

        return $r;
    }
}
