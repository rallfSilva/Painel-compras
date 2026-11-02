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

## Inteligência (Etapa 3B)

| Método | Rota | Permissão | Descrição |
|---|---|---|---|
| GET | `/inteligencia/itens-adjudicados` | `registros.ver` | Itens com resultado e todos os campos da seção 16, paginados (`pagina`, `por_pagina`, `ordem`, `direcao`, `busca`, `situacao[]`). Aceita `caminho` da árvore de decomposição. |
| GET | `/inteligencia/classes` | `dashboard.ver` | Resumo e valor por classe (grupo, tipo, itens, compras, processos, órgãos, fornecedores). |
| GET | `/inteligencia/classes/{id}` | `dashboard.ver` | Itens da classe por unidade de fornecimento (quantidade, preço médio, mínimo, máximo). |
| GET | `/catalogo` | `dashboard.ver` | Itens do catálogo, paginados (`busca`, `status[]`, `grupo_id`, `classe_id`, `apenas_duplicados`). Não usa filtros globais. |
| GET | `/catalogo/resumo` | `dashboard.ver` | Totais por status, duplicidades, itens sem compra, equivalências e opções dos formulários. |
| GET | `/catalogo/{id}` | `dashboard.ver` | Ficha: dados, duplicidades, semelhantes, equivalências e histórico de alterações. |
| GET | `/catalogo/duplicidades` | `dashboard.ver` | Grupos de itens com a mesma descrição normalizada e unidade. |
| GET | `/catalogo/equivalencias?status=` | `dashboard.ver` | Propostas e decisões de equivalência. |
| POST / PUT | `/catalogo`, `/catalogo/{id}` | `cadastros.gerenciar` | Cria ou edita item; grava o antes/depois no histórico. |
| POST | `/catalogo/equivalencias` | `cadastros.gerenciar` | Propõe equivalência (`item_id`, `equivalente_id`, `justificativa`). |
| POST | `/catalogo/equivalencias/{id}/decisao` | `cadastros.gerenciar` | `decisao` VALIDAR ou REJEITAR, `justificativa` (mín. 10 caracteres) e `confirmar_especificacao` quando as especificações diferem. |
| GET | `/inteligencia/analise-total` | `dashboard.ver` | Dimensões da árvore, unidade superior × contratante, amparo × classe, compras recorrentes, concentração (HHI) e itens com maior variação de preço. |
| GET | `/inteligencia/analise-total/decomposicao?dimensao=&caminho=ano:2025,unidade:12` | `dashboard.ver` | Um nível da árvore de decomposição (valor `nulo` = não informado). |
| GET | `/inteligencia/analise-total/rankings?limite_orgaos=&limite_itens=…` | `dashboard.ver` | Rankings de órgãos, fornecedores, itens, classes, municípios, processos e amparos. |
| GET | `/inteligencia/precos?janela_meses=` | `dashboard.ver` | Resumo e itens com maior dispersão de preço (`janela_meses=0` = todo o histórico). |
| GET | `/inteligencia/precos/itens?busca=` | `dashboard.ver` | Busca de itens com preço registrado, por unidade. |
| GET | `/inteligencia/precos/itens/{id}?unidade_id=&janela_meses=&quantidade_min=&quantidade_max=` | `registros.ver` | Estatísticas, evolução, comparação por órgão, município, fornecedor e ano, estadual × nacional, alertas e referências. |

## Planejamento, contratos e relatórios (Etapa 4)

Todas aceitam os parâmetros de filtro globais.

| Método e rota | Permissão | Descrição |
|---|---|---|
| `GET /pca` | `dashboard.ver` | Indicadores do PCA (planejado, contratado, % executado, diferença, demandas por situação, atrasadas), comparação por órgão, categoria, classe e exercício e contratações fora do PCA |
| `GET /pca/demandas` | `registros.ver` | Demandas paginadas com todos os campos da seção 20, contratado, % executado, `atrasada` e processo vinculado. Locais: `busca`, `situacao[]` (NAO_INICIADA, EM_ANDAMENTO, CONCLUIDA, CANCELADA), `ordem`, `direcao`, `pagina`, `por_pagina` |
| `GET /contratos/painel` | `dashboard.ver` | Vigentes, valor vigente, aditivos, acréscimo médio, sinalizados, vencendo em 30/60/90 dias, vencimentos por mês, por órgão e por situação, parâmetros do alerta |
| `GET /contratos` | `registros.ver` | Contratos paginados com valor atualizado, % acréscimo, `sinal_acrescimo` (ACIMA, PROXIMO), `dias_para_vencer`. Locais: `busca`, `situacao[]`, `vencendo_em` (dias), `sinalizados=1` |
| `GET /contratos/{id}` | `registros.ver` | Contrato com aditivos, itens e execução financeira (`null` enquanto a fonte não for integrada) |
| `GET /relatorios` | `dashboard.ver` | Lista dos 18 relatórios |
| `GET /relatorios/{codigo}` | `relatorios.exportar` | Relatório com `colunas` (id, título, tipo) e `linhas`, até 5.000 (`limitado`, `total_linhas`). Registrado nos logs de auditoria |

## Administração (Etapa 4)

| Método e rota | Permissão | Descrição |
|---|---|---|
| `GET /admin` | autenticado | Cadastros que o usuário pode gerenciar (campos e opções), acesso a usuários/logs e resumo |
| `GET /admin/cadastros/{recurso}` | do recurso | Registros paginados; `busca`; `com_opcoes=1` traz as opções dos campos de referência |
| `POST /admin/cadastros/{recurso}` · `PUT /admin/cadastros/{recurso}/{id}` · `DELETE …/{id}` | do recurso | Inclusão, alteração e exclusão com validação e histórico. Recursos: unidades-superiores, orgaos, unidades-compradoras, fornecedores (`cadastros.gerenciar`); regimes-legais, modalidades, sistematicas, formas-aquisicao, amparos-legais, naturezas, subelementos, equivalencias-regimes (`classificacoes.gerenciar`); integracoes (`integracoes.gerenciar`); configuracoes (`configuracoes.gerenciar`, só o valor) |
| `GET /admin/usuarios` · `POST` · `PUT /admin/usuarios/{id}` | `usuarios.gerenciar` | Usuários, perfis e órgãos; inclusão e alteração (`nome`, `email`, `perfil_id`, `ativo`, `orgaos[]`, `senha`) |
| `GET /admin/perfis` · `PUT /admin/perfis/{id}` | `usuarios.gerenciar` | Matriz de perfis × permissões; `permissoes[]` com os códigos |
| `GET /admin/logs` | `logs.ver` | Logs de auditoria paginados; `acao` (prefixo), `usuario_id`, `busca` |
