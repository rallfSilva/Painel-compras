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

### 2.2 Tabelas (Etapas 1 e 2)

| Grupo | Tabelas |
|---|---|
| Localidades | `estados`, `regioes_planejamento`, `municipios` |
| Órgãos | `unidades_superiores`, `orgaos`, `unidades_compradoras` |
| Fornecedores | `portes_empresariais`, `fornecedores`, `fornecedores_historico` |
| Classificações | `regimes_legais`, `amparos_legais`, `classificacoes_modalidades`, `sistematicas_aquisicao`, `formas_aquisicao`, `classificacoes_natureza`, `naturezas_despesa`, `elementos_despesa`, `subelementos_despesa`, `equivalencias_regimes` |
| Catálogo | `catalogos`, `grupos_materiais`, `classes_materiais`, `unidades_medida`, `itens_catalogo` |
| Compras | `processos`, `processos_fases`, `processos_itens`, `processos_participantes` (Etapa 2: licitantes, para participação e vitórias dos fornecedores) |
| Contratos e atas | `contratos`, `contratos_itens`, `contratos_aditivos`, `contratos_execucao_financeira`, `atas_registro_precos`, `atas_itens`, `atas_participantes`, `atas_utilizacoes` |
| Acesso | `users`, `perfis`, `permissoes`, `perfil_permissao`, `usuario_orgaos`, `personal_access_tokens` |
| Inteligência e administração | `glossario_indicadores`, `configuracoes`, `importacoes`, `importacoes_erros`, `integracoes`, `logs_sincronizacao`, `logs_auditoria`, `historico_alteracoes`, `arquivos_anexos`, `consultas_salvas`, `agregados_compras_mensal` |

Ajuste em relação à lista do prompt: `licitacoes`, `licitacoes_resultados` e `itens_adjudicados` foram unificadas em `processos` e `processos_itens`. Uma licitação é um processo cuja sistemática é do tipo `LICITACAO`, e o item adjudicado é a linha de `processos_itens` com o resultado. Isso evita guardar o mesmo valor em duas tabelas. As tabelas do PCA (`pca*`) e de preços (`precos_referencias`, `historico_precos`) entram nas Etapas 3 e 4.

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

## 3. Estrutura de diretórios

```
painel-compras/
├── backend/                         Laravel 12
│   ├── app/
│   │   ├── Console/Commands/        sic:limpar-demonstrativos
│   │   ├── Http/Controllers/Api/    Auth, Meta, Filtro, Dashboard, Compras, Processo, Fornecedor, Orgao
│   │   ├── Http/Middleware/         ExigePermissao, CabecalhosSeguranca
│   │   ├── Models/                  um model por tabela de domínio
│   │   ├── Services/
│   │   │   ├── Filtros/             FiltrosAplicados, FiltroService, OpcoesFiltroService
│   │   │   ├── Indicadores/         IndicadorService, DashboardService, RegistrosService, OpcoesCalculo
│   │   │   ├── Compras/             ComprasTotais, Panorama, Processos, Itens, Fornecedores, Orgaos
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
| 2. Compras | Compras Totais (10 gráficos e ranking), Panorama por unidade contratante (14 colunas, exportação Excel/CSV/PDF), Licitações e Processos com fases e itens, Fornecedores (ranking, concentração, ficha), Órgãos e Unidades, filtro cruzado | **Entregue neste PR** |
| 3. Inteligência | Painéis geográfico (UF, região, município), por natureza/classe e por sistemática (seções 9 a 11), MPE, Alimentos/PNAE, Licitações para RP (8 gráficos), Aquisições por RP (3 tabelas, sinalizadores), Itens Adjudicados, Catálogo, Compras por Classe, Análise do Total Comprado (árvore de decomposição, amparo legal), Análise de Preços | |
| 4. Planejamento e Contratos | PCA, Contratos e aditivos, Relatórios (Excel, CSV, PDF com rodapé NEXMIND), Administração | |
| 5. Integração | Importação XLSX/CSV/JSON com fila, PNCP, Compras.gov.br, sistema financeiro estadual | |
| 6. IA | Consultas em linguagem natural com provedor configurável (local ou API opcional) | |
