# PHP MVP API

Backend MVP em PHP nativo com padrão em camadas:

- Routes
- Controllers
- Repositories
- Middleware JWT
- Migrações SQL

## Executar local (Supabase Postgres)

1. Copie `.env.example` para `.env`.
2. Configure `DB_DSN`, `DB_USER` e `DB_PASS` com os dados do seu projeto Supabase.
3. Rode `composer install` (opcional se quiser autoload PSR-4 via Composer).
4. Rode `php -S localhost:8000 -t public`.

### Exemplo de conexao no `.env`

`DB_DSN=pgsql:host=db.<project-ref>.supabase.co;port=5432;dbname=postgres;sslmode=require`

`DB_USER=postgres`

`DB_PASS=<senha-do-banco>`

Obs.: esta API usa Supabase como banco Postgres (PDO), com JWT proprio no backend (nao usa Supabase Auth).

## Endpoints principais

- `GET /api/health`
- `POST /api/auth/register`
- `POST /api/auth/login`
- `GET /api/auth/me` (JWT)
- `GET /api/clients` (JWT)
- `POST /api/clients` (JWT admin)
- `GET /api/tasks` (JWT)
- `POST /api/tasks` (JWT)
- `PATCH /api/tasks/{id}/status` (JWT)
- `GET /api/invoices` (JWT)

## Estrutura

- `public/index.php` entrypoint web.
- `src/Core` utilitários de framework.
- `src/Controllers` handlers HTTP.
- `src/Repositories` acesso a dados.
- `src/Middleware` proteção de rotas.
- `database/migrations` schema SQL versionado.
