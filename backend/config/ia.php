<?php

/*
 * Assistente de consultas em linguagem natural (seção 32). Opcional e desacoplado:
 *  - none      : sem modelo de linguagem; as perguntas são interpretadas por regras locais (padrão, gratuito).
 *  - ollama    : modelo local gratuito (ex.: llama3.1, qwen2.5) servido pelo Ollama na rede da SELC.
 *  - openai    : qualquer API compatível com OpenAI (/v1/chat/completions), só se a SELC decidir.
 *  - anthropic : API da Anthropic, só se a SELC decidir.
 * O modelo apenas interpreta a pergunta e escolhe uma consulta autorizada; os números da resposta vêm
 * sempre das consultas do sistema, com os filtros e as permissões do usuário. O modelo nunca acessa o banco.
 */
return [
    'provider' => env('AI_PROVIDER', 'none'),
    'model' => env('AI_MODEL', 'llama3.1'),
    'api_key' => env('AI_API_KEY'),
    'base_url' => env('AI_BASE_URL'),
    'timeout' => (int) env('AI_TIMEOUT', 30),
];
