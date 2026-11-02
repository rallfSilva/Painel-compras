<?php

namespace App\Services\Integracoes;

use App\Support\Rastreabilidade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PNCP — API pública de consulta (https://pncp.gov.br/api/consulta). Coleta contratações (por modalidade)
 * e contratos publicados no período, apenas dos órgãos cadastrados com CNPJ no sistema. Cada registro é
 * identificado pelo número de controle PNCP, o que evita duplicidade em sincronizações repetidas.
 */
class PncpConector implements Conector
{
    use ClienteHttp;

    /** Modalidade PNCP => [sistemática, forma de aquisição]. */
    public const MODALIDADES = [
        1 => ['LEILAO', 'LEILAO'], 2 => ['DIALOGO', 'DIALOGO'], 3 => ['CONCURSO', 'CONCURSO'], 4 => ['CONCORRENCIA', 'CONCORRENCIA_ELETRONICA'],
        5 => ['CONCORRENCIA', 'CONCORRENCIA_PRESENCIAL'], 6 => ['PREGAO', 'PREGAO_ELETRONICO'], 7 => ['PREGAO', 'PREGAO_PRESENCIAL'],
        8 => ['DISPENSA', null], 9 => ['INEXIGIBILIDADE', 'INEXIGIBILIDADE'], 12 => ['CREDENCIAMENTO', 'CREDENCIAMENTO'], 13 => ['LEILAO', 'LEILAO'],
    ];

    private const SITUACAO_COMPRA = [1 => 'EM_ANDAMENTO', 2 => 'REVOGADO', 3 => 'ANULADO', 4 => 'SUSPENSO'];

    public function codigo(): string
    {
        return 'PNCP';
    }

    public function origem(): string
    {
        return Rastreabilidade::ORIGEM_PNCP;
    }

    public function descricao(): string
    {
        return 'Contratações e contratos publicados no Portal Nacional de Contratações Públicas pelos órgãos cadastrados com CNPJ.';
    }

    public function documentacao(): string
    {
        return 'https://pncp.gov.br/api/consulta/swagger-ui/index.html';
    }

    public function parametros(): array
    {
        return [
            'base_url' => ['rotulo' => 'Endereço da API de consulta', 'tipo' => 'texto', 'padrao' => 'https://pncp.gov.br/api/consulta'],
            'uf' => ['rotulo' => 'UF', 'tipo' => 'texto', 'padrao' => 'RR'],
            'modalidades' => ['rotulo' => 'Modalidades PNCP (códigos separados por vírgula)', 'tipo' => 'texto', 'padrao' => '4,5,6,7,8,9,12',
                'ajuda' => '4/5 concorrência, 6/7 pregão, 8 dispensa, 9 inexigibilidade, 12 credenciamento.'],
            'contratos' => ['rotulo' => 'Coletar contratos', 'tipo' => 'booleano', 'padrao' => true],
            'dias' => ['rotulo' => 'Janela padrão (dias)', 'tipo' => 'numero', 'padrao' => 30],
            'max_paginas' => ['rotulo' => 'Máximo de páginas por consulta', 'tipo' => 'numero', 'padrao' => 20],
        ];
    }

    /** @return array<string, object> CNPJ => órgão */
    private function orgaos(): array
    {
        return DB::table('orgaos')->whereNotNull('cnpj')->get(['id', 'nome', 'sigla', 'cnpj'])
            ->keyBy(fn ($o) => preg_replace('/\D/', '', $o->cnpj))->filter(fn ($o, $k) => strlen($k) === 14)->all();
    }

    public function testar(array $p): array
    {
        $orgaos = $this->orgaos();
        try {
            $hoje = now()->format('Ymd');
            $this->obterJson(rtrim($p['base_url'], '/').'/v1/contratacoes/publicacao',
                ['dataInicial' => $hoje, 'dataFinal' => $hoje, 'codigoModalidadeContratacao' => 6, 'uf' => $p['uf'], 'pagina' => 1, 'tamanhoPagina' => 10]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensagem' => 'Sem resposta do PNCP: '.$e->getMessage()];
        }
        if ($orgaos === []) {
            return ['ok' => false, 'mensagem' => 'O PNCP respondeu, mas nenhum órgão tem CNPJ cadastrado. Informe o CNPJ em Administração › Órgãos para delimitar o que é coletado.'];
        }

        return ['ok' => true, 'mensagem' => 'PNCP respondeu. '.count($orgaos).' órgão(s) com CNPJ serão consultados.'];
    }

    public function coletar(array $p, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $base = rtrim($p['base_url'], '/');
        $orgaos = $this->orgaos();
        if ($orgaos === []) {
            throw new \RuntimeException('Nenhum órgão tem CNPJ cadastrado: não há como delimitar a coleta no PNCP.');
        }
        $unidades = [];
        $processos = [];
        $ignorados = 0;
        $fora = [];
        $modalidades = array_filter(array_map('intval', explode(',', (string) $p['modalidades'])));
        foreach ($modalidades as $mod) {
            foreach ($this->paginas("$base/v1/contratacoes/publicacao", ['dataInicial' => $de->format('Ymd'), 'dataFinal' => $ate->format('Ymd'),
                'codigoModalidadeContratacao' => $mod, 'uf' => $p['uf']], (int) $p['max_paginas']) as $r) {
                $cnpj = preg_replace('/\D/', '', (string) data_get($r, 'orgaoEntidade.cnpj'));
                $controle = (string) ($r['numeroControlePNCP'] ?? '');
                if (isset($processos[$controle]) || isset($fora[$controle])) {
                    continue;                                   // a mesma compra veio em outra consulta
                }
                if (! isset($orgaos[$cnpj])) {
                    $fora[$controle] = true;
                    $ignorados++;

                    continue;
                }
                $un = $this->unidade($r, $orgaos[$cnpj]);
                $unidades[$un['unidade_codigo']] = $un;
                [$sist, $forma] = self::MODALIDADES[(int) ($r['modalidadeId'] ?? $mod)] ?? ['OUTRA', null];
                $processos[$controle] = [
                    'identificador' => 'PNCP:'.$r['numeroControlePNCP'], 'numero_publicacao' => trim(($r['numeroCompra'] ?? '').'/'.($r['anoCompra'] ?? ''), '/'),
                    'numero_processo_sei' => $r['processo'] ?? null, 'unidade' => $un['unidade_codigo'], 'objeto' => $r['objetoCompra'] ?? null,
                    'data_abertura' => $r['dataAberturaProposta'] ?? $r['dataPublicacaoPncp'] ?? null, 'data_publicacao' => $r['dataPublicacaoPncp'] ?? null,
                    'sistematica' => $sist, 'forma' => $forma, 'registro_precos' => (bool) ($r['srp'] ?? false),
                    'situacao' => self::SITUACAO_COMPRA[(int) ($r['situacaoCompraId'] ?? 1)] ?? 'EM_ANDAMENTO',
                    'valor_estimado' => $r['valorTotalEstimado'] ?? null, 'valor_homologado' => $r['valorTotalHomologado'] ?? null,
                    'link_pncp' => sprintf('https://pncp.gov.br/app/editais/%s/%s/%s', $cnpj, $r['anoCompra'] ?? '', $r['sequencialCompra'] ?? ''),
                ];
            }
        }

        $contratos = [];
        if (filter_var($p['contratos'], FILTER_VALIDATE_BOOLEAN)) {
            foreach (array_keys($orgaos) as $cnpj) {
                foreach ($this->paginas("$base/v1/contratos", ['dataInicial' => $de->format('Ymd'), 'dataFinal' => $ate->format('Ymd'), 'cnpjOrgao' => $cnpj], (int) $p['max_paginas']) as $r) {
                    $un = $this->unidade($r, $orgaos[$cnpj]);
                    $unidades[$un['unidade_codigo']] = $un;
                    $compra = isset($r['numeroControlePncpCompra']) ? 'PNCP:'.$r['numeroControlePncpCompra'] : null;
                    $temCompra = $compra && (DB::table('processos')->where('uid', $compra)->exists() || isset($processos[substr($compra, 5)]));
                    $contratos[] = [
                        'identificador' => 'PNCP:'.$r['numeroControlePNCP'], 'numero' => $r['numeroContratoEmpenho'] ?? $r['numeroControlePNCP'],
                        'unidade' => $un['unidade_codigo'], 'fornecedor_documento' => $r['niFornecedor'] ?? null, 'fornecedor_nome' => $r['nomeRazaoSocialFornecedor'] ?? null,
                        'numero_publicacao' => $temCompra ? $compra : null, 'objeto' => $r['objetoContrato'] ?? null, 'valor_inicial' => $r['valorInicial'] ?? null,
                        'valor_atualizado' => $r['valorGlobal'] ?? null, 'data_assinatura' => $r['dataAssinatura'] ?? null,
                        'vigencia_inicio' => $r['dataVigenciaInicio'] ?? null, 'vigencia_fim' => $r['dataVigenciaFim'] ?? null,
                    ];
                }
            }
        }

        return array_values(array_filter([
            ['tipo' => 'UNIDADES', 'descricao' => 'Unidades compradoras do PNCP', 'linhas' => array_values($unidades)],
            ['tipo' => 'PROCESSOS', 'descricao' => 'Contratações do PNCP', 'linhas' => array_values($processos), 'ignorados' => $ignorados],
            ['tipo' => 'CONTRATOS', 'descricao' => 'Contratos do PNCP', 'linhas' => $contratos],
        ], fn ($l) => $l['linhas'] !== []));
    }

    private function unidade(array $r, object $orgao): array
    {
        return [
            'orgao_cnpj' => $orgao->cnpj, 'orgao_nome' => $orgao->nome, 'orgao_sigla' => $orgao->sigla,
            'unidade_codigo' => (string) data_get($r, 'unidadeOrgao.codigoUnidade'), 'unidade_nome' => data_get($r, 'unidadeOrgao.nomeUnidade') ?: $orgao->nome,
            'municipio' => data_get($r, 'unidadeOrgao.codigoIbge'),
        ];
    }

    /** @return \Generator<array<string, mixed>> */
    private function paginas(string $url, array $query, int $max): \Generator
    {
        for ($pg = 1; $pg <= max(1, $max); $pg++) {
            $j = $this->obterJson($url, $query + ['pagina' => $pg, 'tamanhoPagina' => 50]);
            foreach ($j['data'] ?? [] as $r) {
                yield $r;
            }
            if (($j['paginasRestantes'] ?? 0) <= 0 || empty($j['data'])) {
                break;
            }
        }
    }
}
