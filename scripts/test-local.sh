#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

echo "[1/2] Clearing Laravel caches..."
php artisan optimize:clear >/dev/null

echo "[2/2] Running PHPUnit with isolated in-memory SQLite..."
php artisan test "$@"
