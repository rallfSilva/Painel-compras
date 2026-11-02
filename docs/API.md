# API REST — Etapa 1

Base: `/api`. Respostas em JSON. Exceto o login, toda rota exige `Authorization: Bearer <token>`.

## Autenticação

| Método | Rota | Descrição |
|---|---|---|
| POST | `/auth/login` | `{email, password}` → `{token, usuario}`. Limite de 5 tentativas por minuto. |
| GET | `/auth/me` | Usuário atual, perfil e permissões. |
| POST | `/auth/logout` | Revoga o token atual. |

## Metadados

| Método | Rota | Permissão | Descrição |
|---|---|---|---|
| GET | `/meta` | `dashboard.ver` | Período coberto pelos dados, última atualização, presença de dados fictícios. |
| GET | `/glossario` | `dashboard.ver` | Glossário de indicadores (nome, descrição, fórmula, fonte, critérios). |

## Filtros

| Método | Rota | Descrição |
|---|---|---|
| GET | `/filtros/opcoes` | Árvores de todos os filtros. Cada nó: `{key, label, children?, group?}`. |
| GET | `/filtros/buscar/{item\|fornecedor\|publicacao}?q=` | Busca para filtros de alta cardinalidade (mínimo 2 caracteres). |

### Parâmetros de filtro (aceitos por todas as rotas analíticas)

| Parâmetro | Valores |
|---|---|
| `periodo` | `todo`, `ano_atual`, `ano_anterior`, `ultimos_5`, `ultimos_10`, `personalizado` |
| `data_inicio`, `data_fim` | `AAAA-MM-DD` (com `periodo=personalizado`) |
| `f[tempo][]` | `a:2025`, `s:2025-1`, `t:2025-3`, `m:2025-07` |
| `f[porte][]` | `p:<id>` |
| `f[sistematica][]` | `s:<id>` (sistemática), `f:<id>` (forma de aquisição) |
| `f[natureza][]` | `n:<id>` (tipo), `nd:<id>`, `el:<id>`, `se:<id>` (natureza, elemento, subelemento) |
| `f[classe][]` | `g:<id>` (grupo), `c:<id>` (classe) |
| `f[item][]` | `i:<id>` |
| `f[comprador_local][]` | `r:<id>` (região), `m:<id>` (município) |
| `f[unidade][]` | `us:<id>`, `o:<id>`, `u:<id>` (unidade superior, órgão, unidade) |
| `f[rp][]` | `sim`, `nao` |
| `f[publicacao][]` | número de publicação (ex.: `2025/00123`) |
| `f[amparo][]` | `rg:<id>` (regime), `a:<id>` (amparo) |
| `f[fornecedor][]` | `fo:<id>` |
| `f[fornecedor_local][]` | `uf:SP`, `m:<id>` |

Nós diferentes do mesmo filtro combinam com **OU**. Filtros diferentes combinam com **E**. Um filtro informado sem nenhum valor válido não retorna nada, em vez de retornar tudo.

Opções de cálculo: `economia_base=adjudicado|homologado|contratado` e `incluir_sem_disputa=1|0`. Sem elas, valem os padrões da tabela `configuracoes`.

## Dashboard

| Método | Rota | Permissão | Descrição |
|---|---|---|---|
| GET | `/dashboard/indicadores` | `dashboard.ver` | 21 indicadores com `valor`, `valor_anterior`, `variacao_percentual`, `tendencia`, `informado` e `explicacao`. |
| GET | `/dashboard/graficos` | `dashboard.ver` | `evolucao_anual`, `por_sistematica`, `top_unidades`, `por_grupo_porte`. |
| GET | `/dashboard/indicadores/{codigo}/registros?pagina=&por_pagina=` | `registros.ver` | Registros que compõem o indicador, com os mesmos filtros. O CPF de pessoa física sai mascarado sem `dados_pessoais.ver`. |

Exemplo:

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "http://sic-selc.local/api/dashboard/indicadores?periodo=ano_atual&f[unidade][]=o:3&f[rp][]=sim"
```
