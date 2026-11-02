# Arquitetura técnica — SIC/SELC

Este documento atende à seção final do prompt master (v2): arquitetura técnica, modelo de dados, estrutura de diretórios e plano de desenvolvimento.

## 1. Visão geral

```
┌──────────────────────────────┐        HTTPS / JSON         ┌──────────────────────────────────────┐
│  Frontend (SPA)              │ ──────────────────────────▶ │  Backend Laravel 12 (API REST)       │
│  React 19 + TypeScript       │   Authorization: Bearer     │  ├─ Controllers (finos)              │
│  Vite · Tailwind · Radix     │                             │  ├─ Services (camada de cálculo)     │
│  TanStack Query/Table        │                             │  │   ├─ FiltroService (filtros)      │
│  Recharts                    │                             │  │   ├─ IndicadorService (fórmulas)  │
└──────────────────────────────┘                             │  │   ├─ RegistrosService (detalhe)   │
        ▲ build em backend/public/app                        │  │   └─ AgregacaoService (resumos)   │
        │ servido pelo Apache/XAMPP                          │  ├─ Middleware de permissões         │
                                                             │  └─ Sanctum (tokens)                 │
                                                             └──────────────┬───────────────────────┘
                                                                            │
                                                             ┌──────────────▼───────────────────────┐
                                                             │  MySQL 8 / MariaDB 10.4+ (XAMPP)     │
                                                             │  dimensões · fatos · resumos · logs  │
                                                             └──────────────────────────────────────┘
```

Decisões principais:

| Tema | Decisão | Motivo |
|---|---|---|
| Versão do Laravel | 12 (PHP 8.2+) | O XAMPP atual traz PHP 8.2; o Laravel 13 exige PHP 8.3. |
| Banco | MySQL 8 ou MariaDB 10.4+ | MariaDB é o banco do XAMPP. Testes rodam também em SQLite. |
| Autenticação | Laravel Sanctum com token Bearer, expiração de 8 h | Funciona igual em `npm run dev` e no Apache, sem configurar cookies entre domínios. |
| Filas | Driver `database` | O XAMPP não tem Redis. Usado nas importações (Etapa 5). |
| Cálculos | Somente nos Services do backend | Seção 28: nenhum componente visual calcula indicador. |
| Filtros | Um único `FiltroService`, chaves com prefixo (`u:15`, `a:2025`) | Garante que indicador, gráfico e lista de registros usam exatamente o mesmo recorte. |
| Resumos | Tabela `agregados_compras_mensal`, recalculada após cada carga | O MySQL não tem visões materializadas. |
| Fontes | Inter empacotada (`@fontsource`) | O servidor institucional pode não ter acesso à internet. |
| Build | O frontend compila para `backend/public/app` e o build fica no repositório | Instalação no XAMPP sem Node.js. |

## 2. Modelo de dados

### 2.1 Princípios

- **Rastreabilidade**: toda tabela de dados tem `origem` (MANUAL, IMPORTACAO, PNCP, COMPRASGOV, SISTEMA_ESTADUAL, DEMONSTRATIVO), `importacao_id`, `ficticio` e `fonte_atualizado_em` (`App\Support\Rastreabilidade`).
- **Chaves naturais únicas** (`uid`) em processos, contratos e atas, e `documento` único em fornecedores, para evitar duplicidade entre cargas.
- **Valores de cada etapa em colunas separadas**: estimado, adjudicado, homologado, contratado (itens) e empenho, liquidação, pagamento (`contratos_execucao_financeira`). Nada é somado entre etapas.
- **Ausência ≠ zero**: valores não informados ficam `NULL` e a API devolve `null` ("Não informado").
- **Dados fictícios separados**: `ficticio = true`. A interface mostra um aviso enquanto eles existirem e `php artisan sic:limpar-demonstrativos` os remove.

### 2.2 Tabelas (Etapas 1 a 4)

| Grupo | Tabelas |
|---|---|
| Localidades | `estados`, `regioes_planejamento`, `municipios` |
| Órgãos | `unidades_superiores`, `orgaos`, `unidades_compradoras` |
| Fornecedores | `portes_empresariais`, `fornecedores`, `fornecedores_historico` |
| Classificações | `regimes_legais`, `amparos_legais`, `classificacoes_modalidades`, `sistematicas_aquisicao`, `formas_aquisicao`, `classificacoes_natureza`, `naturezas_despesa`, `elementos_despesa`, `subelementos_despesa`, `equivalencias_regimes` |
| Catálogo | `catalogos`, `grupos_materiais`, `classes_materiais`, `unidades_medida`, `itens_catalogo` |
| Compras | `processos`, `processos_fases`, `processos_itens`, `processos_participantes` (Etapa 2: licitantes, para participação e vitórias dos fornecedores) |
| Colunas da Etapa 3A | `itens_catalogo.categoria_alimento` (CARNES, AVES, PEIXES, FRUTAS, VERDURAS, LEGUMES, CEREAIS, GRAOS, LATICINIOS, INDUSTRIALIZADOS) e `itens_catalogo.perecivel`; `processos_itens.beneficio_mpe` (NENHUM, EXCLUSIVO, COTA_RESERVADA). A tabela `equivalencias_regimes` recebe as equivalências entre as modalidades da Lei 8.666/10.520 e da Lei 14.133 |
| Contratos e atas | `contratos`, `contratos_itens`, `contratos_aditivos`, `contratos_execucao_financeira`, `atas_registro_precos`, `atas_itens`, `atas_participantes`, `atas_utilizacoes` |
| Acesso | `users`, `perfis`, `permissoes`, `perfil_permissao`, `usuario_orgaos`, `personal_access_tokens` |
| Inteligência e administração | `glossario_indicadores`, `configuracoes`, `importacoes`, `importacoes_erros`, `integracoes`, `logs_sincronizacao`, `logs_auditoria`, `historico_alteracoes`, `arquivos_anexos`, `consultas_salvas`, `agregados_compras_mensal` |

Ajuste em relação à lista do prompt: `licitacoes`, `licitacoes_resultados` e `itens_adjudicados` foram unificadas em `processos` e `processos_itens`. Uma licitação é um processo cuja sistemática é do tipo `LICITACAO`, e o item adjudicado é a linha de `processos_itens` com o resultado. Isso evita guardar o mesmo valor em duas tabelas. As tabelas do PCA (`pca*`) e de preços (`precos_referencias`, `historico_precos`) entram nas Etapas 3 e 4.

Na Etapa 3B entra a tabela `itens_equivalencias` (item, equivalente, status PROPOSTA/VALIDADA/REJEITADA, justificativa, especificações diferentes, quem propôs, quem validou e quando). A migração preenche `itens_catalogo.descricao_normalizada` dos itens existentes.

Na Etapa 4 entram as tabelas do PCA: `pca` (exercício e órgão, situação, aprovação, link do PNCP), `pca_demandas` (todos os campos da seção 20: unidade requisitante, número da demanda, código da contratação, objeto, necessidade, categoria, grupo/classe, quantidade e valor estimados, data pretendida, prioridade, situação do planejamento, responsável, processo SEI e situação de execução), `pca_itens` (itens do catálogo previstos) e `pca_execucao` (vínculo da demanda com os processos que a executam).

### 2.3 Relacionamentos principais

```
unidades_superiores 1─N orgaos 1─N unidades_compradoras N─1 municipios N─1 regioes_planejamento
                                          │
                                          1
                                          N
sistematicas 1─N formas_aquisicao    processos ──N─1 amparos_legais N─1 regimes_legais
         └──────────────1─N──────────────┘ │
                                           1
                                           N
              itens_catalogo 1─N  processos_itens  N─1 fornecedores N─1 portes_empresariais
              classes N─1 grupos           │                       N─1 municipios
                                           │
              contratos (processo_id) ─────┘      atas_registro_precos (processo_id) 1─N atas_itens
```

### 2.4 Regras de cálculo implementadas

Todas estão no glossário (`glossario_indicadores`, endpoint `/api/glossario`) e no tooltip de cada cartão.

- **Total estimado**: soma do estimado dos itens com resultado (adjudicado, homologado ou contratado).
- **Economia**: soma de `estimado − referência` nos itens comparáveis. A referência é configurável (`economia.base_comparacao`: adjudicado, homologado ou contratado; padrão contratado). Por padrão só entram procedimentos com disputa de preço (`sistematicas_aquisicao.com_disputa`). Chamada Pública PNAE, credenciamento, inexigibilidade e adesão ficam fora, com opção na tela para incluir.
- **% Economia**: economia / estimado dos mesmos itens comparáveis × 100.
- **Contratos vigentes e próximos do vencimento**: situação na data de hoje. Usam os filtros de órgão, fornecedor etc., mas não o período. O prazo de alerta é configurável (padrão 90 dias).
- **Empenhado, liquidado e pago**: `null` enquanto `contratos_execucao_financeira` estiver vazia, ou seja, sem integração com o sistema financeiro.
- **Panorama por unidade contratante (Etapa 2)**: *Total comprado* = soma do valor contratado dos itens. *% MPE*, *% no Estado* e *% na região* usam como base o valor contratado elegível (itens com valor contratado e fornecedor informado). *% na região* considera a região escolhida na tela ou, sem escolha, a mesma região de planejamento do comprador. *% com disputa* é calculado por quantidade de processos ou por valor, conforme escolha explícita na tela. *% eletrônicos* = processos eletrônicos / processos.
- **Mediana da diferença em relação ao Estado**: para cada preço unitário pago pela unidade, compara com a mediana dos preços do mesmo item de catálogo, mesma unidade de fornecimento e mesmo ano pagos pelas **demais** unidades (mínimo configurável em `precos.minimo_observacoes`, padrão 3). A coluna mostra a mediana dessas diferenças. Itens sem código de catálogo não são comparados.
- **Indicadores visuais do Panorama**: faixas verde/amarelo/vermelho definidas em `PanoramaService::FAIXAS` e explicadas no tooltip de cada sinal. O sinal sempre tem ícone e rótulo, nunca só cor.
- **Concentração de fornecedores**: participação dos 5 e 10 maiores, índice HHI (baixa < 1.500, moderada até 2.500, alta acima) e sinalizadores de unidades em que um fornecedor supera `fornecedores.limite_concentracao` (padrão 30%) do valor contratado, com ao menos 2 fornecedores e 5 processos. São sinais de necessidade de análise, não conclusões.
- **Fases do processo**: o rito muda conforme o procedimento (`App\Support\FasesProcesso`). Licitações têm as 11 fases; dispensa, inexigibilidade, adesão, credenciamento e Chamada Pública PNAE têm ritos reduzidos.
- **Período anterior**: para presets e período personalizado, desloca o intervalo pelo mesmo tamanho. Para seleção de anos na árvore, desloca pelo número de anos. Em "todo o histórico" não há comparação.

#### Etapa 3A (seções 9 a 15)

- **MPE**: MEI, ME e EPP (grupo `MPE` de `portes_empresariais`). Produtores familiares (PAF) e cooperativas (COOP) ficam em categoria própria, fora das MPE. Os percentuais usam o valor contratado dos itens com fornecedor informado. *Compras exclusivas* e *cota reservada* vêm de `processos_itens.beneficio_mpe`. "Fornecedor local" é o sediado em Roraima.
- **Alimentos**: item com `categoria_alimento` ou processo de natureza "Gêneros alimentícios". Preço médio = Σ adjudicado / Σ quantidade, sempre por item **e** unidade de fornecimento. *Agricultura familiar* = Chamada Pública PNAE ou fornecedor PAF/cooperativa.
- **PNAE**: o mínimo legal (`pnae.percentual_minimo_af`, padrão 30%, Lei 11.947/2009, art. 14) incide sobre os repasses do FNDE, que ainda não estão integrados. O painel mostra uma **aproximação**: participação da agricultura familiar no valor contratado de alimentos, identificada como tal na tela.
- **Licitações para RP**: grandeza principal = valor adjudicado dos processos com `registro_precos`. Economia = estimado − adjudicado nos itens com os dois valores. Os gráficos "RP Sim x Não" ignoram o filtro global de RP, porque comparam os dois grupos. O gráfico de valor e nº de itens por ano foi separado em dois (sem eixo duplo).
- **Aquisições por RP**: registrado = Σ qtd. registrada × unitário registrado; estimado = Σ qtd. registrada × unitário estimado; comprado = Σ `atas_utilizacoes.valor_total`; economia efetiva = Σ qtd. utilizada × (unitário estimado − unitário registrado); % utilizado = comprado / registrado. Por órgão: % saldo = (previsto − adquirido) / previsto, com previsto = Σ quantidade prevista × unitário registrado. As três tabelas filtram umas às outras (`ata_id`, `item_id`, `unidade_id`).
- **Sinalizadores das atas**: utilização acima de 100% (algum item usado além da quantidade registrada), vencimento próximo (`atas.dias_alerta_vencimento`), alta (`atas.utilizacao_alta`, 80%) e baixa (`atas.utilizacao_baixa`, 30%) utilização. Indicam necessidade de análise, nunca irregularidade.
- **Sistemática**: valores e economia vêm dos itens; situação, forma eletrônica/presencial e tempo vêm dos processos. % disputa efetiva = processos com 2 ou mais participantes. Tempo médio = abertura → homologação nos concluídos. A série comparável soma cada modalidade antiga à equivalente da Lei 14.133 sem alterar a classificação original.
- **Mapas**: malha municipal do IBGE (pacote `ibge-cidades-com-poligonos`, licença ISC) simplificada para os 15 municípios de Roraima, e UFs do pacote `@svg-maps/brazil` (CC BY 4.0), em `frontend/src/assets/geo/mapas.json`. A atribuição aparece no rodapé do painel. As regiões de planejamento vêm de `regioes_planejamento`.

#### Etapa 3B (seções 16 a 19 e 24)

- **Itens adjudicados**: apenas itens com resultado (adjudicado, homologado, contratado). % economia do item = (total estimado − total adjudicado) / total estimado × 100. CPF de pessoa física sai mascarado sem `dados_pessoais.ver`.
- **Catálogo**: a descrição normalizada (`App\Support\Normalizador`) fica em maiúsculas, sem acentos e pontuação, com número e unidade juntos (5 KG → 5KG) e abreviações padronizadas (PCT → PACOTE, UNID → UN). *Duplicidade* = mesma descrição normalizada e mesma unidade de fornecimento. *Semelhante* = mesma classe e índice de Jaccard ≥ 50% entre os termos (só palavras de ligação são ignoradas, para que TAMANHO M e TAMANHO G não se confundam). *Equivalência* nunca é automática: é proposta e só vale depois de validação com justificativa; itens com especificações diferentes exigem confirmação explícita, e a decisão vai para `logs_auditoria`. Toda criação e edição grava o antes/depois em `historico_alteracoes`. O catálogo não segue os filtros globais. A importação em massa do catálogo fica para a Etapa 5.
- **Compras por classe**: valor contratado por grupo e classe. Quantidades de unidades de fornecimento diferentes nunca são somadas; ao abrir a classe, quantidade e preço aparecem por item e unidade.
- **Árvore de decomposição**: agregação no backend, um nível por vez (`/inteligencia/analise-total/decomposicao`). O caminho aberto vira condição exata (E) da consulta, sem substituir os filtros globais. Dimensões: ano, unidade superior, unidade contratante, grupo, classe, sistemática, forma, amparo, porte, UF do fornecedor e município comprador; sequência padrão Ano › Unidade › Grupo › Classe › Sistemática › Forma. Os registros de um nó são os itens adjudicados com o mesmo `caminho`.
- **Rankings**: limites padrão 10 órgãos, 20 fornecedores, 50 itens, 20 classes, 10 municípios, 10 processos e 10 amparos, ajustáveis de 1 a 200.
- **Compras recorrentes**: mesmo item e unidade em `compras.recorrencia_minima` processos ou mais (padrão 3). Indica oportunidade de planejamento conjunto ou RP, nunca fracionamento.
- **Concentração**: HHI = Σ (participação % de cada fornecedor)², de 0 a 10.000, no total e por classe; a classe é sinalizada quando um fornecedor tem `fornecedores.limite_concentracao` (30%) ou mais.
- **Análise de preços**: referência = valor unitário adjudicado de itens com resultado, do mesmo item **e** unidade de fornecimento, na janela `precos.janela_meses` (24) até a data de referência e, quando informada, na faixa de quantidade. Estatísticas: mínimo, máximo, média, mediana, desvio padrão amostral, CV = desvio / média × 100 e variação = (máx − mín) / mín × 100. Alertas: preço a `precos.alerta_mediana_pct` (30%) ou mais da mediana, CV ≥ `precos.alerta_cv_pct` (35%), menos de `precos.minimo_observacoes` (3) referências e mediana anual que sobe `precos.alerta_aumento_pct` (25%) ou mais. Todo alerta traz o aviso de que pede análise humana e não constitui conclusão sobre sobrepreço. A comparação estadual × nacional mostra "fonte não integrada" até a Etapa 5.

#### Etapa 4 (seções 20, 22, 25 e 29)

- **PCA**: planejado = Σ valor estimado das demandas não excluídas do plano; contratado = Σ valor contratado dos itens dos processos vinculados em `pca_execucao`; % executado = contratado / planejado × 100. *Atrasada* = data pretendida já passou e a demanda não foi concluída nem cancelada. *Fora do PCA* = processos com os filtros atuais sem vínculo com demanda (pede análise, não indica irregularidade). O PCA segue os filtros de período (como exercício), órgão/unidade e grupo/classe e o acesso por órgão.
- **Contratos**: valor atualizado = valor inicial + Σ aditivos de valor ou mistos (quando a fonte não traz o valor atualizado). % acréscimo = Σ aditivos de valor / valor inicial × 100, comparado ao limite do art. 125 da Lei 14.133/2021 (`contratos.limite_acrescimo` = 25%, `contratos.limite_acrescimo_reforma` = 50%). O contrato é *sinalizado* a partir de `contratos.alerta_proximo_limite` (80%) do limite e marcado como *acima do limite* quando o ultrapassa. Prazos de vencimento em `contratos.prazos_alerta` (30, 60, 90 dias), contados a partir de hoje, independentemente do período. Empenhado, liquidado e pago aparecem quando a fonte financeira estadual for integrada (Etapa 5); até lá a tela informa "fonte não integrada".
- **Relatórios**: 18 relatórios (geral, órgão, fornecedor, modalidade, economia, MPE, município, natureza, classe, itens, atas, saldo de atas, PCA, preços, desertos/fracassados, contratos, amparo legal e PNAE), gerados no servidor com os filtros globais, até 5.000 linhas, exportados em Excel, CSV e PDF (cabeçalho institucional, filtros aplicados, numeração de páginas e rodapé "Desenvolvido por NEXMIND"). Cada geração é registrada em `logs_auditoria` (`relatorio.gerar`). Exige `relatorios.exportar`.
- **Administração**: cadastros e classificações por um registro único de recursos (`CadastrosService::RECURSOS`) com validação, permissão própria por recurso e histórico antes/depois em `historico_alteracoes`. Usuários: o usuário não desativa a si mesmo; perfis restritos por órgão exigem ao menos um órgão; senha com 8+ caracteres, letras e números; a troca de senha encerra as outras sessões. Perfis: o Administrador Geral mantém todas as permissões e ninguém tira do próprio perfil a permissão de gerenciar usuários. Logs de auditoria com filtro por tipo de ação.

## 3. Estrutura de diretórios

```
painel-compras/
├── backend/                         Laravel 12
│   ├── app/
│   │   ├── Console/Commands/        sic:limpar-demonstrativos
│   │   ├── Http/Controllers/Api/    Auth, Meta, Filtro, Dashboard, Compras, Processo, Fornecedor, Orgao, Inteligencia, Analise, Planejamento, Admin
│   │   ├── Http/Middleware/         ExigePermissao, CabecalhosSeguranca
│   │   ├── Models/                  um model por tabela de domínio
│   │   ├── Services/
│   │   │   ├── Filtros/             FiltrosAplicados, FiltroService, OpcoesFiltroService
│   │   │   ├── Indicadores/         IndicadorService, DashboardService, RegistrosService, OpcoesCalculo
│   │   │   ├── Compras/             ComprasTotais, Panorama, Processos, Itens, Fornecedores, Orgaos
│   │   │   ├── Inteligencia/        painéis das Etapas 3A e 3B
│   │   │   ├── Planejamento/        PcaService, ContratosService
│   │   │   ├── Relatorios/          RelatoriosService (18 relatórios)
│   │   │   ├── Admin/               CadastrosService, UsuariosService
│   │   │   ├── AgregacaoService.php
│   │   │   └── Configuracoes.php
│   │   └── Support/                 Rastreabilidade, FasesProcesso, DadosPessoais
│   ├── database/migrations/         modelo de dados
│   ├── database/seeders/            Referencia, Acesso, Glossario, Demonstrativo
│   ├── public/app/                  build do frontend (gerado)
│   ├── routes/api.php               endpoints da API
│   └── tests/                       Feature (cálculos, API) e Unit
├── frontend/                        React + TypeScript + Vite
│   └── src/
│       ├── assets/brand/            logos NEXMIND (rodapé)
│       ├── components/
│       │   ├── compras/             gráficos com filtro cruzado, Panorama, faixa de indicadores
│       │   ├── dashboard/           cartões, gráficos, detalhamento de registros
│       │   ├── detalhes/            fichas de unidade, processo e fornecedor
│       │   ├── tabela/              tabela avançada, tabela paginada no servidor, exportação
│       │   ├── filters/             árvore de filtros, busca, barra de filtros
│       │   ├── layout/              menu lateral, cabeçalho, rodapé de créditos
│       │   └── ui/                  componentes base (botão, cartão, diálogo...)
│       ├── contexts/                autenticação, filtros globais, tema
│       ├── lib/                     cliente da API, formatação, tipos
│       ├── pages/                   Dashboard, Compras Totais, Processos, Fornecedores, Órgãos, Login, módulos planejados
│       └── modulos.ts               os 19 módulos do menu e suas etapas
└── docs/                            arquitetura, instalação, API
```

## 4. Plano de desenvolvimento

| Etapa | Escopo | Situação |
|---|---|---|
| 1. Fundação | Estrutura, banco, autenticação e perfis, layout institucional (cabeçalho de período, rodapé NEXMIND, modo escuro), 13 filtros globais, Dashboard Geral com indicadores, gráficos e detalhamento | Entregue |
| 2. Compras | Compras Totais (10 gráficos e ranking), Panorama por unidade contratante (14 colunas, exportação Excel/CSV/PDF), Licitações e Processos com fases e itens, Fornecedores (ranking, concentração, ficha), Órgãos e Unidades, filtro cruzado | Entregue |
| 3A. Inteligência | Painéis geográfico (mapas de RR e do Brasil, região, município), por natureza/classe (ranking dos 50 itens, evolução de preços) e por sistemática (equivalência entre regimes), MPE, Alimentos/PNAE, Licitações para RP (12 indicadores, 8 gráficos), Aquisições por RP (14 indicadores, 3 tabelas cruzadas, sinalizadores) | Entregue |
| 3B. Inteligência | Itens Adjudicados, Catálogo (normalização, duplicidades, semelhantes, equivalências validadas, histórico), Compras por Classe, Análise do Total Comprado (árvore de decomposição, unidades, amparo legal, rankings, recorrência, concentração, variação de preços), Análise de Preços | Entregue |
| 4. Planejamento e Contratos | PCA (demandas, planejado × contratado, atrasadas, fora do PCA), Contratos (vencimentos 30/60/90, aditivos, alerta de acréscimo do art. 125), 18 Relatórios gerenciais (Excel, CSV, PDF com cabeçalho institucional e rodapé NEXMIND), Administração (cadastros, classificações, parâmetros, usuários, perfis e permissões, logs) | **Entregue neste PR** |
| 5. Integração | Importação XLSX/CSV/JSON com fila, PNCP, Compras.gov.br, sistema financeiro estadual | |
| 6. IA | Consultas em linguagem natural com provedor configurável (local ou API opcional) | |
