<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Interpretação por modelo de linguagem (Ollama local, API compatível com OpenAI ou Anthropic).
 * O modelo recebe só a pergunta e o catálogo de consultas, nunca dados do banco, e devolve um JSON
 * com a consulta escolhida e os parâmetros. Tudo é validado contra o catálogo; se o modelo falhar,
 * demorar ou responder fora do formato, vale o interpretador por regras.
 */
final class InterpretadorLlm
{
    public function __construct(private readonly InterpretadorRegras $regras) {}

    public static function ativo(): bool
    {
        return in_array(config('ia.provider'), ['ollama', 'openai', 'anthropic'], true);
    }

    public function interpretar(string $pergunta): Interpretacao
    {
        $provedor = (string) config('ia.provider');
        try {
            $json = $this->chamar($provedor, self::instrucoes(), $pergunta);
            $dados = self::extrairJson($json);
            if ($dados === null || ! array_key_exists('consulta', $dados)) {
                throw new \RuntimeException('Resposta fora do formato esperado.');
            }
            $consulta = CatalogoConsultas::existe($dados['consulta'] ?? null) ? $dados['consulta'] : null;

            // Parâmetros: os extraídos por regras são a base; o modelo só completa com valores válidos.
            $p = InterpretadorRegras::parametros($pergunta);
            $m = is_array($dados['parametros'] ?? null) ? $dados['parametros'] : [];
            if (! isset($p['termo']) && is_string($m['termo'] ?? null) && trim($m['termo']) !== '') {
                $p['termo'] = mb_substr(trim($m['termo']), 0, 80);
            }
            if (! isset($p['anos']) && is_array($m['anos'] ?? null)) {
                $anos = array_values(array_filter(array_map('intval', $m['anos']), fn ($a) => $a >= 1950 && $a <= 2100));
                if ($anos) {
                    $p['anos'] = array_slice(array_unique($anos), 0, 20);
                }
            }
            if (! isset($p['limite']) && is_numeric($m['limite'] ?? null)) {
                $p['limite'] = max(1, min(100, (int) $m['limite']));
            }
            if (! isset($p['dias']) && is_numeric($m['dias'] ?? null)) {
                $p['dias'] = max(1, min(365, (int) $m['dias']));
            }

            return new Interpretacao($consulta, $p, $provedor);
        } catch (\Throwable $e) {
            Log::warning('Assistente: modelo de linguagem indisponível, usando regras locais.', ['provedor' => $provedor, 'erro' => $e->getMessage()]);
            $r = $this->regras->interpretar($pergunta);

            return new Interpretacao($r->consulta, $r->parametros, 'regras', 'O modelo de linguagem configurado não respondeu; a pergunta foi interpretada pelas regras locais.');
        }
    }

    private static function instrucoes(): string
    {
        $lista = collect(CatalogoConsultas::lista())->map(fn ($c) => "- {$c['codigo']}: {$c['descricao']} Exemplo: \"{$c['exemplo']}\"")->implode("\n");

        return <<<TXT
        Você classifica perguntas sobre compras públicas do Governo de Roraima. Escolha UMA consulta da lista
        ou null se nenhuma servir. Não responda a pergunta nem invente números: só classifique.
        Consultas:
        $lista
        Responda somente com JSON neste formato:
        {"consulta": "codigo_ou_null", "parametros": {"termo": "tipo de material ou serviço citado, ou null", "anos": [2025], "limite": 10, "dias": 90}}
        Omita parâmetros que a pergunta não cita.
        TXT;
    }

    private function chamar(string $provedor, string $sistema, string $pergunta): string
    {
        $timeout = max(3, (int) config('ia.timeout', 30));
        $modelo = (string) config('ia.model');
        $chave = (string) config('ia.api_key');
        $base = rtrim((string) config('ia.base_url'), '/');

        return match ($provedor) {
            'ollama' => (string) Http::timeout($timeout)->post(($base ?: 'http://127.0.0.1:11434').'/api/chat', [
                'model' => $modelo, 'stream' => false, 'format' => 'json', 'options' => ['temperature' => 0],
                'messages' => [['role' => 'system', 'content' => $sistema], ['role' => 'user', 'content' => $pergunta]],
            ])->throw()->json('message.content'),
            'openai' => (string) Http::timeout($timeout)->withToken($chave)->post(($base ?: 'https://api.openai.com').'/v1/chat/completions', [
                'model' => $modelo, 'temperature' => 0, 'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $sistema], ['role' => 'user', 'content' => $pergunta]],
            ])->throw()->json('choices.0.message.content'),
            'anthropic' => (string) Http::timeout($timeout)->withHeaders(['x-api-key' => $chave, 'anthropic-version' => '2023-06-01'])
                ->post(($base ?: 'https://api.anthropic.com').'/v1/messages', [
                    'model' => $modelo, 'max_tokens' => 300, 'system' => $sistema,
                    'messages' => [['role' => 'user', 'content' => $pergunta]],
                ])->throw()->json('content.0.text'),
            default => throw new \RuntimeException('Provedor não suportado.'),
        };
    }

    /** @return array<string, mixed>|null */
    public static function extrairJson(string $texto): ?array
    {
        if (preg_match('/\{.*\}/s', $texto, $m)) {
            $d = json_decode($m[0], true);

            return is_array($d) ? $d : null;
        }

        return null;
    }
}
