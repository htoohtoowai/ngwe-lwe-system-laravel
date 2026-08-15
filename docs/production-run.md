# Production Runbook

This document is for running Ngwe Lwe System in production with Docker Compose.

The production database is treated as persistent data. Do not reset it unless you intentionally want to destroy production records.

## Important current domain rule

`service_type_id` is no longer part of the production business flow. Do not add legacy service-type migrations, seeders, filters, or backfills for production.

The current production model is based on companies, accounts, features, fees, exchange rates, teller floats, vault notes, and transactions.

## Prerequisites

- Docker and Docker Compose are installed on the production server.
- The server has enough disk space for the MySQL volume and backups.
- DNS, firewall, and reverse proxy are prepared if the app is exposed through a public domain.
- The repository is checked out on the production server.
- A database backup plan exists before every deployment that changes migrations.

## Environment file

Create the production `.env` from the example:

```bash
cp .env.example .env
```

Set these values before starting containers:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-production-domain.example

APP_HOST_PORT=8001
APP_SERVER_PORT=8000

DOCKER_DB_DATABASE=ngwe_lwe_laravel
DOCKER_DB_USERNAME=replace_with_strong_user
DOCKER_DB_PASSWORD=replace_with_strong_password
DOCKER_DB_ROOT_PASSWORD=replace_with_strong_root_password


REVERB_APP_ID=replace_with_reverb_app_id
REVERB_APP_KEY=replace_with_reverb_app_key
REVERB_APP_SECRET=replace_with_reverb_app_secret
VITE_REVERB_APP_KEY=replace_with_same_value_as_REVERB_APP_KEY
```

Generate secure values:

```bash
openssl rand -base64 32
openssl rand -hex 32
```

Generate `APP_KEY` after the image is buildable:

```bash
docker compose build app
docker compose run --rm --entrypoint php app artisan key:generate --show
```

Copy the generated value into `.env`:

```dotenv
APP_KEY=base64:generated_value_here
```

Runtime validation will block startup when:

- `APP_KEY` is missing or still a placeholder.
- `APP_DEBUG=true` while `APP_ENV=production`.
- `VITE_REVERB_APP_KEY` does not match `REVERB_APP_KEY`.
- Required DB or Reverb values are missing.

## First production start

Build and start the services:

```bash
docker compose build app reverb
docker compose up -d mysql redis
docker compose up -d app reverb
```

Run migrations and production-safe seeders:

```bash
docker compose exec -T app php artisan migrate --seed --force
```

Do not use `migrate:fresh` in production.

Check service status:

```bash
docker compose ps
curl -I http://localhost:8001/up
```

If `APP_HOST_PORT` is not `8001`, use the configured port in the health check URL.

## Normal production deployment

Use this flow for normal updates:

```bash
git pull --ff-only origin main
docker compose build app reverb
docker compose up -d --force-recreate app reverb
docker compose exec -T app php artisan migrate --force
docker compose ps
curl -I http://localhost:8001/up
```

If new required seed data was added, run:

```bash
docker compose exec -T app php artisan db:seed --force
```

Do not delete the MySQL volume during normal deployments.

Avoid these commands in production unless you intentionally want to reset data:

```bash
docker compose down -v
php artisan migrate:fresh
php artisan migrate:fresh --seed
```

## Logs and diagnostics

App logs:

```bash
docker compose logs -f app
```

Realtime server logs:

```bash
docker compose logs -f reverb
```

Database logs:

```bash
docker compose logs -f mysql
```

Migration status:

```bash
docker compose exec -T app php artisan migrate:status
```

Application health:

```bash
curl -I http://localhost:8001/up
```

## Database backup

Create a local backup folder:

```bash
mkdir -p backups
```

Backup the Docker MySQL database:

```bash
docker compose exec -T mysql sh -lc 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > backups/ngwe_lwe_$(date +%Y%m%d_%H%M%S).sql
```

Restore a backup only when you intentionally want to replace current database contents:

```bash
docker compose exec -T mysql sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < backups/backup_file.sql
```

Always take a fresh backup before restoring.

## Rollback

For an application-only rollback:

```bash
git checkout previous_commit_or_tag
docker compose build app reverb
docker compose up -d --force-recreate app reverb
curl -I http://localhost:8001/up
```

If the failed deployment already ran irreversible migrations, application rollback may not be enough. Restore the database backup taken before the deployment.

## Production account setup

Demo users are for local testing only. Production should use real staff accounts and strong credentials.

If no owner/admin account exists after first deployment, create one through a controlled one-time operation, then immediately set the real password and PIN through the application flow.

## Operational notes

- Keep `.env` private and never commit it.
- Keep MySQL host port closed to public traffic unless there is a strict operational reason to expose it.
- The named Docker volume `mysql-data` stores production DB files.
- Exchange-rate calculations should use the latest exchange-rate record, not a hard-coded rate.
- Teller cash movement must still follow active-float, note denomination, PIN, and balance rules.
