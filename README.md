# Ngwe Lwe System

Laravel + Vue 3 + Inertia.js money-transfer operations system for Admin, Cashier, and Teller users.

## Stack

- Laravel
- PHP 8.4
- Vue 3
- Inertia.js
- Tailwind CSS
- MySQL
- Laravel Reverb / Echo

## Architecture

The customer demo is a session-authenticated Laravel web monolith. The browser does not use `/api/*` endpoints.

```text
Browser
  -> routes/web.php
  -> Laravel session auth + role middleware
  -> FormRequest
  -> Controller
  -> Service / Repository
  -> Eloquent / MySQL
  -> redirect or Inertia response
  -> Vue page
```

Vue form submissions use Inertia `router.post`, `router.patch`, `router.put`, and `router.delete`. Page reads are delivered as Inertia props from Laravel controllers/services.

Realtime private-channel authorization uses the same Laravel web session through `/broadcasting/auth`; no bearer token is required.

See `docs/inertia-web-architecture.md` and `docs/final-database-design.md`.

## Business model

- Providers (`companies`) can be Pay, Bank, or Both.
- Every account is exactly `PAY` or `BANK`.
- `account_identifier` is unique within a provider, not globally.
- BANK accounts cannot be agent accounts.
- PAY accounts may be marked `is_agent`.
- Account feature assignments control where an account may be used: Cash In, Cash Out, Send Money, Receive Money, Transfer, Exchange.
- Customer fees use feature-based `provider_fee_tiers`.
- Transfer customer fees use route-based `transfer_fee_tiers`.
- Agent commission tiers are feature-independent and use one amount range with OUT and IN commission values.
- Agent commission applies only to eligible PAY agent accounts and is selected from the principal balance movement direction.
- Fee/commission calculation types support `FIXED` and `PERCENTAGE`, with configured values down to `0.0001`.
- Actual earned/reversed commissions are recorded in `agent_commission_entries`.

## Transaction flows

- Cash In: teller entry -> pending Cashier confirmation -> completed/cancelled.
- Cash Out: teller entry -> immediate completion after validations.
- Transfer: source/destination account movement with route fee and applicable agent IN/OUT commissions.
- Exchange: provider exchange rate + account movement + applicable agent IN/OUT commission.
- Teller physical cash is controlled by teller float denominations.
- Cashier manages main-vault cash and teller float issue/return.

## Local run

```bash
docker compose build app reverb
docker compose up -d
docker compose exec -T app php artisan migrate:fresh --seed --force
docker compose exec -T app php artisan migrate:status
```

For tests on the host, install development dependencies first. The default PHPUnit suite uses isolated in-memory SQLite, so it does not touch the Docker/demo MySQL database:

```bash
composer install
php artisan test
npm ci
npm run types:check
npm run build
```

For MySQL 8.4 integration verification, use `./scripts/test-mysql.sh`.

The executable PHPUnit suite targets the Inertia/session architecture. Obsolete API integration tests were removed and replaced with session-authenticated Inertia web tests.

## Production deployment

Do not use `migrate:fresh` or delete the MySQL volume in production. Normal deployment uses:

```bash
git pull --ff-only origin main
docker compose build app reverb
docker compose up -d --force-recreate app reverb
docker compose exec -T app php artisan migrate --force
docker compose ps
curl -I http://localhost:8001/up
```


## Local tests

Default fast suite:

```bash
./scripts/test-local.sh
```

Optional MySQL 8.4 integration suite (dedicated disposable test DB on host port 3308):

```bash
./scripts/test-mysql.sh
```
