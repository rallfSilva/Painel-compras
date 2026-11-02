<?php

namespace App\Services\Filtros;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Representação imutável dos filtros globais (seção 6.3) e do período (seção 6.2).
 *
 * Formato na query string:
 *   periodo=todo|ano_atual|ano_anterior|ultimos_5|ultimos_10|personalizado
 *   data_inicio=YYYY-MM-DD&data_fim=YYYY-MM-DD   (período personalizado)
 *   f[<filtro>][]=<prefixo>:<valor>             (nós das árvores de filtro)
 */
final class FiltrosAplicados
{
    public const FILTROS = [
        'tempo', 'porte', 'sistematica', 'natureza', 'classe', 'item', 'comprador_local',
        'unidade', 'rp', 'publicacao', 'amparo', 'fornecedor', 'fornecedor_local',
    ];

    public const PERIODOS = ['todo', 'ano_atual', 'ano_anterior', 'ultimos_5', 'ultimos_10', 'personalizado'];

    /**
     * @param  array<string, list<string>>  $selecoes
     * @param  list<int>|null  $orgaosPermitidos  restrição de acesso por órgão (null = todos)
     */
    public function __construct(
        public readonly string $periodo = 'todo',
        public readonly ?CarbonImmutable $dataInicio = null,
        public readonly ?CarbonImmutable $dataFim = null,
        public readonly array $selecoes = [],
        public readonly ?array $orgaosPermitidos = null,
        public readonly ?CarbonImmutable $hoje = null,
    ) {}

    public static function doRequest(Request $request): self
    {
        $periodo = in_array($request->query('periodo'), self::PERIODOS, true) ? $request->query('periodo') : 'todo';
        $f = $request->query('f', []);
        $selecoes = [];
        if (is_array($f)) {
            foreach (self::FILTROS as $nome) {
                $valores = $f[$nome] ?? [];
                $valores = is_array($valores) ? $valores : [$valores];
                $valores = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $valores), fn ($v) => $v !== '' && strlen($v) <= 120));
                if ($valores) {
                    $selecoes[$nome] = array_slice($valores, 0, 500);
                }
            }
        }

        return new self(
            periodo: $periodo,
            dataInicio: self::data($request->query('data_inicio')),
            dataFim: self::data($request->query('data_fim')),
            selecoes: $selecoes,
            orgaosPermitidos: $request->user()?->orgaosPermitidos(),
        );
    }

    private static function data(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function hoje(): CarbonImmutable
    {
        return $this->hoje ?? CarbonImmutable::today();
    }

    /** @return list<string> */
    public function valores(string $filtro): array
    {
        return $this->selecoes[$filtro] ?? [];
    }

    /**
     * Intervalo de datas do seletor de período, ou null para todo o histórico.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function intervalo(): ?array
    {
        $hoje = $this->hoje();

        return match ($this->periodo) {
            'ano_atual' => [$hoje->startOfYear(), $hoje->endOfYear()->startOfDay()],
            'ano_anterior' => [$hoje->subYear()->startOfYear(), $hoje->subYear()->endOfYear()->startOfDay()],
            'ultimos_5' => [$hoje->subYears(4)->startOfYear(), $hoje->endOfYear()->startOfDay()],
            'ultimos_10' => [$hoje->subYears(9)->startOfYear(), $hoje->endOfYear()->startOfDay()],
            'personalizado' => ($this->dataInicio || $this->dataFim)
                ? [$this->dataInicio ?? CarbonImmutable::create(1900), $this->dataFim ?? $hoje->endOfYear()->startOfDay()]
                : null,
            default => null,
        };
    }

    /**
     * Mesmos filtros, com a seleção de um filtro substituída (ou removida, com lista vazia).
     * Usado no detalhamento de uma unidade, fornecedor etc. e no filtro cruzado.
     *
     * @param  list<string>  $valores
     */
    public function com(string $filtro, array $valores): self
    {
        $selecoes = $this->selecoes;
        if ($valores) {
            $selecoes[$filtro] = $valores;
        } else {
            unset($selecoes[$filtro]);
        }

        return new self($this->periodo, $this->dataInicio, $this->dataFim, $selecoes, $this->orgaosPermitidos, $this->hoje);
    }

    public function semPeriodo(): self
    {
        $selecoes = $this->selecoes;
        unset($selecoes['tempo']);

        return new self('todo', null, null, $selecoes, $this->orgaosPermitidos, $this->hoje);
    }

    /**
     * Mesmos filtros deslocados para o período imediatamente anterior, ou null quando não há período
     * comparável (todo o histórico, ou período e árvore de tempo usados ao mesmo tempo).
     */
    public function periodoAnterior(): ?self
    {
        $intervalo = $this->intervalo();
        $tempo = $this->valores('tempo');

        if ($intervalo && ! $tempo) {
            [$ini, $fim] = $intervalo;
            if ($this->periodo === 'personalizado') {
                $dias = $ini->diffInDays($fim) + 1;
                $novoFim = $ini->subDay();
                $novoIni = $novoFim->subDays($dias - 1);
            } else {
                $anos = $fim->year - $ini->year + 1;
                $novoIni = $ini->subYears($anos);
                $novoFim = $fim->subYears($anos);
            }

            return new self('personalizado', $novoIni, $novoFim, $this->selecoes, $this->orgaosPermitidos, $this->hoje);
        }

        if ($tempo && ! $intervalo) {
            $anos = [];
            foreach ($tempo as $chave) {
                if (preg_match('/^[astm]:(\d{4})/', $chave, $m)) {
                    $anos[(int) $m[1]] = true;
                }
            }
            if (! $anos) {
                return null;
            }
            $deslocamento = max(array_keys($anos)) - min(array_keys($anos)) + 1;
            $novos = array_map(fn ($chave) => preg_replace_callback('/^([astm]):(\d{4})/', fn ($m) => $m[1].':'.((int) $m[2] - $deslocamento), $chave), $tempo);
            $selecoes = $this->selecoes;
            $selecoes['tempo'] = $novos;

            return new self('todo', null, null, $selecoes, $this->orgaosPermitidos, $this->hoje);
        }

        return null;
    }

    /** Descrição legível do período, usada nas respostas e no cabeçalho dos relatórios. */
    public function descricaoPeriodo(): string
    {
        if ($i = $this->intervalo()) {
            return $i[0]->format('d/m/Y').' a '.$i[1]->format('d/m/Y');
        }
        if ($t = $this->valores('tempo')) {
            return 'Seleção: '.implode(', ', $t);
        }

        return 'Todo o histórico';
    }
}
