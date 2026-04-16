# PHP MVP API (MongoDB)

Backend em PHP nativo com:

- Routes
- Controllers
- Repositories
- Middleware JWT
- MongoDB (colecoes e indices)

## Executar local

1. Copie `.env.example` para `.env`.
2. Configure `MONGODB_URI`, `MONGODB_DATABASE`, `APP_KEY` e `JWT_TTL`.
3. Rode `composer install`.
4. Rode `php -S localhost:8000 -t public`.

### Exemplo de `.env`

`MONGODB_URI=mongodb+srv://usuario:senha@cluster0.xxxxx.mongodb.net/united_flow?retryWrites=true&w=majority`

`MONGODB_DATABASE=united_flow`

`APP_KEY=troque-esta-chave-por-uma-chave-segura`

`JWT_TTL=3600`

## Endpoints principais

- `GET /api/diagnostic` — testa só Mongo + extensão (sem bootstrap pesado). Use quando o login der 500.
- `GET /api/health`
- `POST /api/auth/login`
- `GET /api/auth/me` (JWT)
- `POST /api/admin/users` (JWT admin)
- `GET /api/clients` (JWT)
- `POST /api/clients` (JWT admin)
- `GET /api/tasks` (JWT)
- `POST /api/tasks` (JWT)
- `PATCH /api/tasks/{id}/status` (JWT)
- `GET /api/invoices` (JWT)
- `POST /api/invoices` (JWT admin)

### Criacao de usuarios

- `POST /api/auth/register` retorna `403` (registro publico desativado).
- Novos usuarios devem ser criados por admin em `POST /api/admin/users`.

## Estrutura

- `public/index.php` entrypoint web.
- `src/Core` utilitarios de framework.
- `src/Controllers` handlers HTTP.
- `src/Repositories` acesso a dados MongoDB.
- `src/Middleware` protecao de rotas.

## Deploy no Railway (Dockerfile)

Variaveis minimas:

- `MONGODB_URI`
- `MONGODB_DATABASE` (opcional se ja estiver na URI)
- `APP_KEY`
- `JWT_TTL`
- `CORS_ORIGINS` (ex.: `http://localhost:8082`)

### MongoDB Atlas (500 ao conectar)

No Atlas, abra **Network Access** e permita o IP do servidor (para testes, **0.0.0.0/0**). Sem isso, o backend no Railway costuma falhar com erro de conexão e o login retorna **500**.

Se a senha do usuario do banco tiver caracteres especiais, ela precisa estar **codificada na URI** (ou use senha so com letras/numeros para evitar).

Seed inicial opcional de admin:

- `SEED_ADMIN_NAME`
- `SEED_ADMIN_EMAIL`
- `SEED_ADMIN_PASSWORD`

### CORS (front em outro dominio / localhost)

O navegador envia um `OPTIONS` (preflight) antes de chamadas como `POST /api/auth/login`.
A API responde CORS no inicio de `public/index.php`, antes de tocar no banco.
