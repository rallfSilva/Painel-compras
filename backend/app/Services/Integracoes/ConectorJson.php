<?php

namespace App\Services\Integracoes;

use Carbon\CarbonImmutable;

/**
 * Conector genérico para APIs JSON (Compras.gov.br, sistema financeiro e outros sistemas estaduais).
 * A URL aceita {dataInicial}, {dataFinal} e {pagina}; o mapeamento liga cada campo do importador a um
 * caminho no JSON da fonte. Não presume que as fontes tenham os mesmos campos: tudo é configurável.
 */
class ConectorJson implements Conector
{
    use ClienteHttp;

    public function __construct(private readonly string $codigo, private readonly string $origem, private readonly string $descricao,
        private readonly string $documentacao, private readonly array $padroes) {}

    public function codigo(): string
    {
        return $this->codigo;
    }

    public function origem(): string
    {
        return $this->origem;
    }

    public function descricao(): string
    {
        return $this->descricao;
    }

    public function documentacao(): string
    {
        return $this->documentacao;
    }

    public function parametros(): array
    {
        return [
            'url' => ['rotulo' => 'URL da consulta', 'tipo' => 'texto', 'padrao' => $this->padroes['url'] ?? '',
                'ajuda' => 'Use {dataInicial}, {dataFinal} e {pagina}. Ex.: https://api.exemplo.gov.br/despesas?de={dataInicial}&ate={dataFinal}&pagina={pagina}'],
            'formato_data' => ['rotulo' => 'Formato das datas na URL', 'tipo' => 'texto', 'padrao' => $this->padroes['formato_data'] ?? 'Y-m-d'],
            'caminho_dados' => ['rotulo' => 'Caminho da lista no JSON', 'tipo' => 'texto', 'padrao' => $this->padroes['caminho_dados'] ?? 'resultado', 'ajuda' => 'Vazio quando o JSON já é a lista.'],
            'importador' => ['rotulo' => 'Tipo de registro', 'tipo' => 'texto', 'padrao' => $this->padroes['importador'] ?? 'PROCESSOS'],
            'mapeamento' => ['rotulo' => 'Mapeamento (JSON: campo → caminho na fonte)', 'tipo' => 'json', 'padrao' => $this->padroes['mapeamento'] ?? '{}'],
            'dias' => ['rotulo' => 'Janela padrão (dias)', 'tipo' => 'numero', 'padrao' => 30],
            'max_paginas' => ['rotulo' => 'Máximo de páginas', 'tipo' => 'numero', 'padrao' => 20],
        ];
    }

    private function url(array $p, CarbonImmutable $de, CarbonImmutable $ate, int $pagina): string
    {
        return strtr((string) $p['url'], ['{dataInicial}' => $de->format($p['formato_data']), '{dataFinal}' => $ate->format($p['formato_data']), '{pagina}' => $pagina]);
    }

    private function token(array $p): ?string
    {
        // O token fica só no servidor (.env → config/integracoes.php), nunca no banco nem na tela.
        return config('integracoes.tokens.'.$this->codigo) ?: null;
    }

    public function testar(array $p): array
    {
        if (! $p['url']) {
            return ['ok' => false, 'mensagem' => 'Informe a URL da consulta e o mapeamento dos campos para ativar esta integração.'];
        }
        try {
            $j = $this->obterJson($this->url($p, now()->toImmutable()->subDays(7), now()->toImmutable(), 1), [], $this->token($p));
            $lista = $p['caminho_dados'] ? data_get($j, $p['caminho_dados']) : $j;

            return is_array($lista)
                ? ['ok' => true, 'mensagem' => 'A fonte respondeu com '.count($lista).' registro(s) na primeira página dos últimos 7 dias.']
                : ['ok' => false, 'mensagem' => "A fonte respondeu, mas não há lista em \"{$p['caminho_dados']}\"."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensagem' => 'Sem resposta da fonte: '.$e->getMessage()];
        }
    }

    public function coletar(array $p, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        if (! $p['url']) {
            throw new \RuntimeException('Integração sem URL configurada.');
        }
        $mapa = is_array($p['mapeamento']) ? $p['mapeamento'] : (json_decode((string) $p['mapeamento'], true) ?: []);
        if ($mapa === []) {
            throw new \RuntimeException('Configure o mapeamento dos campos antes de sincronizar.');
        }
        $linhas = [];
        $usaPagina = str_contains((string) $p['url'], '{pagina}');
        for ($pg = 1; $pg <= max(1, (int) $p['max_paginas']); $pg++) {
            $j = $this->obterJson($this->url($p, $de, $ate, $pg), [], $this->token($p));
            $lista = $p['caminho_dados'] ? data_get($j, $p['caminho_dados'], []) : $j;
            if (! is_array($lista) || $lista === []) {
                break;
            }
            foreach ($lista as $r) {
                $linhas[] = array_map(fn ($caminho) => is_string($caminho) ? data_get($r, $caminho) : null, $mapa);
            }
            if (! $usaPagina) {
                break;
            }
        }

        return [['tipo' => $p['importador'], 'descricao' => $this->codigo.' · '.$p['importador'], 'linhas' => $linhas]];
    }
}
