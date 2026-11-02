# SIC/SELC — Inteligência de Compras Públicas

Plataforma de Business Intelligence de compras públicas da Secretaria de Estado de Licitação e Contratação de Roraima (SELC/RR).

**Situação:** Todas as etapas entregues: 1 (Fundação), 2 (Compras), 3A e 3B (Inteligência), 4 (Planejamento e Contratos), 5 (Importação e Integrações) e 6 (Inteligência Artificial): banco de dados, autenticação com 7 perfis, layout institucional, 13 filtros globais, Dashboard Geral, Compras Totais com painéis por estado/região (mapas), natureza/classe e sistemática, Panorama por unidade contratante, Compras de MPE, Compras de Alimentos/PNAE, Licitações e Aquisições por Registro de Preços, Itens Adjudicados, Catálogo de Bens e Serviços, Compras por Classe, Análise do Total Comprado (árvore de decomposição), Análise de Preços, Licitações e Processos, Fornecedores, Órgãos e Unidades, Plano de Contratações Anual, Contratos, 18 Relatórios gerenciais e Administração (cadastros, usuários, perfis e logs), Importação de dados (XLSX, XLS, CSV, JSON, com validação e fila), integrações com PNCP, Compras.gov.br e sistema financeiro, e o Assistente de consultas em linguagem natural com alertas e recomendações gerenciais (regras locais por padrão, Ollama ou API opcional), com filtro cruzado e exportação para Excel, CSV e PDF. Veja o [plano de desenvolvimento](docs/ARQUITETURA.md#4-plano-de-desenvolvimento).

- Backend: Laravel 12 (PHP 8.2+), MySQL/MariaDB, Sanctum
- Frontend: React 19, TypeScript, Vite, Tailwind CSS, Radix UI, TanStack Query, Recharts

## Documentação

- [Arquitetura, modelo de dados e plano](docs/ARQUITETURA.md)
- [Instalação no XAMPP](docs/INSTALACAO_XAMPP.md)
- [API REST](docs/API.md)

## Início rápido (desenvolvimento)

```bash
cd backend && cp .env.example .env && composer install && php artisan key:generate
php artisan migrate --seed      # SEED_DEMO=true cria dados FICTÍCIOS para testes
php artisan serve
# em outro terminal
cd frontend && cp .env.example .env && npm install && npm run dev
```

Acesse http://localhost:5173 com `admin@sic-selc.local` / `Trocar@123`.

> Os dados demonstrativos são fictícios e identificados como tal na interface. Remova-os com `php artisan sic:limpar-demonstrativos` antes de carregar dados oficiais.

---

Desenvolvido por [NEXMIND — Soluções em Tecnologia](https://www.nexmind.net.br).
