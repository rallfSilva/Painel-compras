# SIC/SELC — Inteligência de Compras Públicas

Plataforma de Business Intelligence de compras públicas da Secretaria de Estado de Licitação e Contratação de Roraima (SELC/RR).

**Situação:** Etapa 1 (Fundação) entregue: banco de dados, autenticação com 7 perfis, layout institucional, 13 filtros globais e Dashboard Geral com 21 indicadores. Veja o [plano de desenvolvimento](docs/ARQUITETURA.md#4-plano-de-desenvolvimento).

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
