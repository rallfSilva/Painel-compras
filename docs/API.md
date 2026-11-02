# API REST — Etapas 1 a 3A

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
| GET | `/dashboard/indicadores` | `dashboard.ver` | 22 indicadores com `valor`, `valor_anterior`, `variacao_percentual`, `tendencia`, `informado` e `explicacao`. |
| GET | `/dashboard/graficos` | `dashboard.ver` | `evolucao_anual`, `por_sistematica`, `top_unidades`, `por_grupo_porte`. |
| GET | `/dashboard/indicadores/{codigo}/registros?pagina=&por_pagina=` | `registros.ver` | Registros que compõem o indicador, com os mesmos filtros. O CPF de pessoa física sai mascarado sem `dados_pessoais.ver`. |

Exemplo:

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "http://sic-selc.local/api/dashboard/indicadores?periodo=ano_atual&f[unidade][]=o:3&f[rp][]=sim"
```

## Compras (Etapa 2)

Todas as rotas aceitam os parâmetros de filtro globais. As listagens aceitam `pagina`, `por_pagina` (5 a 100), `ordem`, `direcao` (`asc`/`desc`) e `busca`. Com `exportar=1`, perfis com `relatorios.exportar` podem pedir até 5.000 linhas por página, para a exportação.

| Método | Rota | Permissão | Descrição |
|---|---|---|---|
| GET | `/compras/graficos` | `dashboard.ver` | Evolução anual, compras por órgão, modalidade, natureza, município, fornecedor e grupo de materiais, porte, com e sem disputa, e ranking das 20 maiores unidades. Cada linha agrupada traz `filtro` e `chave` para o filtro cruzado. |
| GET | `/compras/panorama?metrica_disputa=quantidade\|valor&regiao_id=` | `dashboard.ver` | Panorama por unidade contratante (14 colunas), total geral, faixas dos indicadores visuais e lista de regiões. |
| GET | `/orgaos/arvore` | `dashboard.ver` | Unidade superior > órgão > unidade compradora, com contratado, %, processos e fornecedores. |
| GET | `/unidades/{id}` | `dashboard.ver` | Ficha da unidade compradora: cadastro, indicadores, evolução e principais modalidades, fornecedores e grupos. |
| GET | `/processos?situacao[]=&com_resumo=1` | `registros.ver` | Lista de processos. Com `com_resumo=1`, inclui quantidades por situação e tempo médio de conclusão. |
| GET | `/processos/{id}` | `registros.ver` | Ficha do processo (campos da seção 21), fases, itens, participantes, atas, contratos e anexos. |
| GET | `/itens?situacao[]=` | `registros.ver` | Itens dos processos. |
| GET | `/fornecedores` | `registros.ver` | Ranking de fornecedores por valor contratado. |
| GET | `/fornecedores/concentracao` | `dashboard.ver` | Top 5, top 10, índice HHI e sinalizadores de concentração por unidade. |
| GET | `/fornecedores/{id}` | `registros.ver` | Ficha do fornecedor e histórico de fornecimento. CPF, endereço, e-mail e telefone de pessoa física saem protegidos sem `dados_pessoais.ver`. |

Perfis restritos por órgão só veem dados e fichas dos seus órgãos.

## Inteligência (Etapa 3A)

Todas aceitam os filtros globais. Linhas agrupadas trazem `filtro` e `chave` para o filtro cruzado.

| Método | Rota | Permissão | Descrição |
|---|---|---|---|
| GET | `/inteligencia/mpe` | `dashboard.ver` | Indicadores de MPE, categorias de porte (MPE, agricultura familiar, demais, não informado), porte, município do fornecedor, órgão, evolução anual, localização e principais fornecedores MPE. |
| GET | `/inteligencia/alimentos` | `dashboard.ver` | Indicadores, aproximação do mínimo PNAE, categorias, perecibilidade, itens (preço médio, mínimo, máximo, frequência), órgãos, município de origem, fornecedores e evolução da agricultura familiar. |
| GET | `/inteligencia/licitacoes-rp` | `dashboard.ver` | 12 indicadores e os 8 gráficos da seção 14. |
| GET | `/inteligencia/licitacoes-rp/processos` | `registros.ver` | Lista paginada das licitações para RP com os campos da seção 14.2 (`pagina`, `por_pagina`, `ordem`, `direcao`, `busca`). |
| GET | `/inteligencia/aquisicoes-rp?ata_id=&item_id=&unidade_id=` | `dashboard.ver` | 14 indicadores, sinalizadores e as tabelas por ata, por item e por órgão participante; a seleção de uma tabela filtra as outras duas. |
| GET | `/inteligencia/geografico` | `dashboard.ver` | Valor por UF do fornecedor, os 15 municípios de RR (compras, processos, fornecedores, participação econômica), regiões e fornecedores locais x de outros estados. |
| GET | `/inteligencia/municipios/{id}` | `dashboard.ver` | Ficha do município: órgãos, fornecedores, materiais, modalidades e evolução. |
| GET | `/inteligencia/natureza` | `dashboard.ver` | Compras por tipo, natureza, elemento, subelemento, grupo e classe, e ranking dos 50 itens. |
| GET | `/inteligencia/itens/{id}` | `registros.ver` | Evolução anual dos preços por unidade de fornecimento e os processos em que o item foi comprado. |
| GET | `/inteligencia/sistematica` | `dashboard.ver` | Quantidade, valor, economia, % disputa, situações e tempo médio por sistemática; forma de processamento; evolução por classificação e regime; tabela de equivalência e série comparável. Aceita `economia_base` e `incluir_sem_disputa`. |
