# Instalação no XAMPP (Windows)

## Requisitos

- XAMPP com **PHP 8.2 ou superior** e MariaDB/MySQL.
- [Composer](https://getcomposer.org/).
- Node.js 20 ou superior, **só** se for alterar o frontend. O build já vem pronto em `backend/public/app`.
- Extensões PHP habilitadas no `php.ini` (`C:\xampp\php\php.ini`): `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `gd`, `intl`. Remova o `;` do início das linhas `extension=...`.

## 1. Copiar o projeto

Clone ou extraia o repositório em `C:\xampp\htdocs\painel-compras`.

## 2. Criar o banco

Inicie Apache e MySQL no painel do XAMPP. Abra http://localhost/phpmyadmin e crie o banco `sic_selc` com o agrupamento `utf8mb4_unicode_ci`. Pelo console:

```sql
CREATE DATABASE sic_selc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## 3. Configurar o backend

```bat
cd C:\xampp\htdocs\painel-compras\backend
copy .env.example .env
composer install
php artisan key:generate
```

Revise o `.env`: `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` e `SEED_ADMIN_PASSWORD`. Para não criar dados fictícios, defina `SEED_DEMO=false`.

```bat
php artisan migrate --seed
```

## 4. Configurar o Apache (virtual host, recomendado)

Em `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName sic-selc.local
    DocumentRoot "C:/xampp/htdocs/painel-compras/backend/public"
    <Directory "C:/xampp/htdocs/painel-compras/backend/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Em `C:\Windows\System32\drivers\etc\hosts` (como administrador), adicione `127.0.0.1 sic-selc.local`. Confirme que `mod_rewrite` está ativo em `httpd.conf` e reinicie o Apache.

Acesse http://sic-selc.local e entre com o usuário do `.env` (padrão `admin@sic-selc.local` / `Trocar@123`). **Troque a senha.**

### Sem virtual host (subpasta)

Também funciona em http://localhost/painel-compras/backend/public. Nesse caso, recompile o frontend com os caminhos da subpasta, no `frontend/.env`:

```
VITE_BASE=/painel-compras/backend/public/app/
VITE_API_URL=/painel-compras/backend/public/api
VITE_ROUTER_BASE=/painel-compras/backend/public
```

Depois rode `npm install` e `npm run build` na pasta `frontend`.

## 5. Fila de processamento

As importações confirmadas e as sincronizações das integrações (Etapa 5) rodam na fila do banco. Deixe um terminal aberto com:

```bat
php artisan queue:work --timeout=3600
```

A sincronização diária das integrações ativas (2h) usa o agendador do Laravel. Em outro terminal:

```bat
php artisan schedule:work
```

Em servidor, configure os dois comandos como serviço (NSSM ou Agendador de Tarefas no Windows; Supervisor e cron no Linux). Sem o processador da fila, a importação fica "Na fila". Em uma instalação de teste, `QUEUE_CONNECTION=sync` no `.env` processa na hora, sem fila (não recomendado para arquivos grandes).

Arquivos importados ficam em `backend/storage/app/private/importacoes` para permitir o reprocessamento. Tokens de integrações (opcionais) vão no `.env`: `COMPRASGOV_TOKEN` e `FINANCEIRO_RR_TOKEN`.

## 6. Desenvolvimento do frontend

```bat
cd C:\xampp\htdocs\painel-compras\backend
php artisan serve

cd C:\xampp\htdocs\painel-compras\frontend
copy .env.example .env
npm install
npm run dev
```

Abra http://localhost:5173. O Vite encaminha `/api` para o `php artisan serve`. Para publicar, rode `npm run build`: o resultado vai para `backend/public/app`.

## 7. Comandos úteis

| Comando | Para quê |
|---|---|
| `php artisan test` | Roda os testes dos cálculos e da API |
| `php artisan sic:limpar-demonstrativos` | Remove todos os dados fictícios |
| `php artisan sic:recalcular-agregados` | Recalcula a tabela de resumo mensal |
| `php artisan sic:sincronizar PNCP --de=2025-01-01 --ate=2025-01-31` | Coloca na fila a sincronização de uma integração (sem código: todas as ativas) |
| `php artisan db:seed --class=DemonstrativoSeeder` | Cria novamente os dados fictícios |

## 8. Backup

Agende um `mysqldump` diário do banco `sic_selc`, por exemplo:

```bat
C:\xampp\mysql\bin\mysqldump -u root sic_selc > D:\backup\sic_selc_%date:~6,4%%date:~3,2%%date:~0,2%.sql
```
