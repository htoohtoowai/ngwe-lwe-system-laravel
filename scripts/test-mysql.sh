#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

echo "[1/3] Starting dedicated MySQL integration-test database..."
docker compose --profile test up -d --wait mysql-test

echo "[2/3] Clearing Laravel caches..."
php artisan optimize:clear >/dev/null

echo "[3/3] Running PHPUnit against MySQL 8.4..."
./vendor/bin/phpunit --configuration phpunit.mysql.xml "$@"
