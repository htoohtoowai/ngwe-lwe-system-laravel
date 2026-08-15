# Docker Guide

This guide runs the Ngwe Lwe Laravel + Vue app with Docker Compose.

## What Compose Starts

- `app`: Laravel HTTP app, exposed on `APP_HOST_PORT`.
- `reverb`: Laravel Reverb WebSocket server, exposed on `DOCKER_REVERB_HOST_PORT`.
- `mysql`: MySQL 8.4 database for the app.
- `redis`: Redis for Docker cache/broadcast infrastructure.

Octane/Swoole is not used in this Docker setup. Reverb handles realtime browser updates; the app container serves normal Laravel HTTP traffic.

## Prerequisites

- Docker Desktop is installed and running.
- Your Windows user can access the Docker daemon.
- Ports are free:
  - App: `8001` by default.
  - Reverb: `8080` by default.
  - MySQL host port: `3307` by default, mapped to container port `3306`.

## One-Time Setup

Copy the example environment file:

```powershell
Copy-Item .env.example .env
```

Set these required values in `.env` before starting Compose:

```dotenv
APP_KEY=base64:replace-with-a-real-laravel-app-key
REVERB_APP_ID=local
REVERB_APP_KEY=replace-with-a-random-key
REVERB_APP_SECRET=replace-with-a-random-secret
VITE_REVERB_APP_KEY=replace-with-the-same-value-as-REVERB_APP_KEY
```

The Docker startup guard checks these values before either app container starts. It also fails fast if `APP_DEBUG=true` while `APP_ENV=production`, or if `VITE_REVERB_APP_KEY` does not match `REVERB_APP_KEY`.

Docker database defaults are already included in `.env.example`:

```dotenv
DOCKER_DB_HOST=mysql
DOCKER_DB_DATABASE=ngwe_lwe_laravel
DOCKER_DB_USERNAME=ngwe_lwe
DOCKER_DB_PASSWORD=ngwe_lwe_secret
MYSQL_HOST_PORT=3307
DOCKER_REVERB_HOST_PORT=8080
DOCKER_VITE_REVERB_PORT=8080
```

For Docker, keep `DOCKER_DB_HOST=mysql`. `DB_HOST=127.0.0.1` is only for the local Laragon MySQL workflow.

## Validate The Compose File

```powershell
docker compose config --quiet
docker compose config --services
```

Expected services include:

```text
mysql
redis
reverb
app
```

## Build And Start

```powershell
docker compose up -d --build
```

Check container status:

```powershell
docker compose ps
```

Follow logs:

```powershell
docker compose logs -f app
docker compose logs -f reverb
```

## Prepare The Database

Run migrations after containers are healthy:

```powershell
docker compose exec app php artisan migrate --force
```

Seed demo users, setup records, exchange rate, and opening vault cash:

```powershell
docker compose exec app php artisan db:seed --force
```

Demo credentials:

| Username | Password | PIN | Role |
| --- | --- | --- | --- |
| `admin` | `password123` | `1111` | Admin |
| `cashier` | `password123` | `2222` | Cashier |
| `teller` | `password123` | `3333` | Teller |

## Open The App

- App: `http://localhost:8001`
- Health check: `http://localhost:8001/up`
- Reverb port: `8080`
- MySQL from host tools: `127.0.0.1:3307`

## Useful Commands

Run the default test suite from the host (in-memory SQLite):

```powershell
php artisan test
```

For MySQL 8.4 integration verification, start the dedicated test service and use `phpunit.mysql.xml` (or run `./scripts/test-mysql.sh` on macOS/Linux):

```powershell
docker compose --profile test up -d --wait mysql-test
.\vendor\bin\phpunit --configuration phpunit.mysql.xml
```

The production-style `app` image installs Composer dependencies with `--no-dev`, so PHPUnit is intentionally run from the host checkout.

Clear Laravel caches:

```powershell
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear
```

Stop containers:

```powershell
docker compose down
```

Stop containers and delete the Docker MySQL volume:

```powershell
docker compose down -v
```

## Troubleshooting

If Docker says access is denied for `C:\Users\User\.docker\config.json`, fix Docker Desktop permissions or start Docker Desktop as the same Windows user running these commands.

If Docker says it cannot connect to `npipe:////./pipe/docker_engine`, Docker Desktop is not running or the current user cannot access the Docker daemon.

If the app cannot connect to MySQL inside Docker, confirm `.env` has `DOCKER_DB_HOST=mysql`, then run:

```powershell
docker compose ps
docker compose logs mysql
```

If Reverb fails immediately, confirm these `.env` values are non-empty:

```dotenv
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
VITE_REVERB_APP_KEY=
```

`VITE_REVERB_APP_KEY` must be exactly the same value as `REVERB_APP_KEY`; otherwise the browser can load but private realtime channels will not authenticate correctly.

## Run The Full Test Suite Locally

The default PHPUnit suite uses in-memory SQLite and never touches the demo/application MySQL database:

```bash
composer install
php artisan test
```

An optional MySQL 8.4 integration configuration is also included. It uses the isolated `mysql-test` Compose profile on host port `3308`:

```bash
docker compose --profile test up -d --wait mysql-test
./vendor/bin/phpunit --configuration phpunit.mysql.xml
```

The MySQL test service uses `tmpfs`, so its data is discarded when the container is removed:

```bash
docker compose --profile test rm -sf mysql-test
```
