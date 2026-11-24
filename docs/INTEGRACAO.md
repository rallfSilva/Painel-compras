# API de entrada: outros sistemas alimentando o painel

O painel recebe dados de outros sistemas (o NexPlanGov é o primeiro) por uma API própria, em `/api/integracao/v1`. Ela aceita os **mesmos 12 tipos e os mesmos campos** da importação de arquivos, e cada envio passa pelo mesmo caminho: validação, gravação idempotente, histórico com as inconsistências e recálculo dos indicadores.

## 1. Gerar o token do sistema

No servidor do painel:

```bash
php artisan sic:token-integracao NEXPLANGOV
```

- Cria (uma vez) o usuário técnico `nexplangov@integracao.sic-selc.local`, do perfil **Sistema integrado (API)**. Ele não entra pela interface e só tem a permissão `integracao.enviar`.
- Imprime o token **uma única vez**. Guarde-o no `.env` do outro sistema. Rodar o comando de novo gera outro token e revoga o anterior.
- `php artisan sic:token-integracao NEXPLANGOV --revogar` corta o acesso.
- O código do sistema (`NEXPLANGOV`) vira a **origem** dos registros e aparece no Histórico da importação.

O token só abre as rotas abaixo. O token de login de uma pessoa (mesmo de administrador) não serve nelas.

## 2. Rotas

Todas exigem `Authorization: Bearer <token>` e `Accept: application/json`. Limite: 60 requisições por minuto por sistema.

| Método e rota | Descrição |
|---|---|
| `GET /integracao/v1/tipos` | Tipos aceitos, com os campos (`nome`, `rotulo`, `tipo`, `obrigatorio`, `exemplo`) e a `ordem_envio` |
| `POST /integracao/v1/lotes` | Envia um lote. Corpo: `{"tipo": "PROCESSOS", "referencia": "texto livre opcional", "registros": [{campo: valor}, ...]}`, de 1 a 5.000 registros. Responde **202** com o lote na fila |
| `GET /integracao/v1/lotes/{id}` | Situação do lote: `situacao` (NA_FILA, PROCESSANDO, CONCLUIDO, ERRO), `concluido`, `importados`, `rejeitados`, `duplicados`, `novos`, `atualizacoes`, `erros_por_campo`, `mensagem` |
| `GET /integracao/v1/lotes/{id}/erros` | Inconsistências linha a linha, paginadas (`pagina`, `por_pagina`). A `linha` é a posição do registro no lote + 1 |

Cada sistema só enxerga os próprios lotes.

### Formato dos registros

- As chaves são os `nome` dos campos de `GET /tipos` (ex.: `numero_publicacao`, `fornecedor_documento`). Campo desconhecido devolve **422** antes de entrar na fila.
- Datas em `aaaa-mm-dd` (ou dd/mm/aaaa). Números como número JSON (`1234.56`). Booleanos como `true`/`false`. CPF/CNPJ com ou sem máscara.
- Códigos de sistemática, forma, modalidade, amparo e regime são os do painel (ex.: `PREGAO`, `PREGAO_ELETRONICO`, `PREGAO_14133`, `L14133_ART28_I`, `L14133`). O sistema de origem traduz os seus códigos antes de enviar.
- Campo ausente ou `null` não apaga o valor que já existe no painel.

### Idempotência

Envie o cabeçalho `Idempotency-Key` (8 a 100 caracteres, ex.: `nexplangov-contratos-2026-11-24-0001`). Repetir o mesmo lote com a mesma chave devolve **200** com o lote já recebido, sem criar outro. Além disso, a gravação já é idempotente por registro: reenviar um processo, contrato ou fornecedor atualiza o existente.

### Ordem de envio

A validação roda na fila, quando o lote é processado. Por isso um lote pode referenciar o que veio num lote anterior, desde que a ordem seja respeitada:

1. Classificações → 2. Unidades → 3. Fornecedores → 4. Catálogo → 5. PCA → 6. Processos → 7. Resultados → 8. Atas → 9. Contratos → 10. Aditivos → 11. Utilizações de ata → 12. Execução financeira

Rode **um único worker** da fila (`php artisan queue:work`) para que os lotes sejam processados na ordem de chegada.

## 3. Exemplo

```bash
curl -X POST https://painel.exemplo.gov.br/api/integracao/v1/lotes \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Idempotency-Key: nexplangov-contratos-0001" \
  -d '{"tipo":"CONTRATOS","referencia":"NexPlanGov · contrato 10/2026","registros":[{
        "numero":"010/2026","unidade":"925001","fornecedor_documento":"12345678000195","fornecedor_nome":"Comercial Exemplo Ltda",
        "numero_publicacao":"90001/2026","objeto":"Fornecimento de papel A4","valor_inicial":5780.00,
        "vigencia_inicio":"2026-04-10","vigencia_fim":"2027-04-09","situacao":"Vigente"}]}'
# HTTP 202 → {"id": 42, "situacao": "NA_FILA", ...}

curl https://painel.exemplo.gov.br/api/integracao/v1/lotes/42 -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## 4. Instalação

- `php artisan migrate` (coluna de idempotência nas importações).
- A fila precisa estar rodando (`QUEUE_CONNECTION=database` e `php artisan queue:work`).
- Lotes grandes: ajuste `post_max_size` do PHP e o limite de corpo do servidor web (5.000 registros ficam em torno de 5 MB).
