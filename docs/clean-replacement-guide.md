# Clean Replacement Guide

This archive is intentionally a **clean replacement**, not an overlay merge.

## Preserved outside the archive

Keep these local/runtime items from the existing checkout:

- `.git/`
- `.env`
- persistent Docker/MySQL volumes

## Recommended replacement

Extract this archive to a temporary directory and sync it into the existing Git checkout with deletion enabled while excluding local state:

```bash
rsync -a --delete \
  --exclude='.git/' \
  --exclude='.env' \
  --exclude='storage/logs/*.log' \
  /path/to/extracted/ngwe-lwe-system-laravel/ \
  /path/to/current/ngwe-lwe-system-laravel/
```

`--delete` is important: it removes files that were deliberately deleted by the Laravel + Vue + Inertia web-only restructure, so old API/token/legacy files do not remain accidentally.

Then run:

```bash
docker compose build app reverb
docker compose up -d
docker compose exec -T app php artisan migrate:fresh --seed --force
composer install
php artisan test
npm ci
npm run types:check
npm run build
```

For the customer demo database, `migrate:fresh --seed` is intentional. Do not use it on production data later.

Optional MySQL 8.4 integration verification:

```bash
./scripts/test-mysql.sh
```
