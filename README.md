# Ngwe Lwe System Laravel

Laravel + Vue rewrite target for the Python Ngwe Lwe money-transfer system.

```text
Source: C:\laragon\www\ngwe-lwe-system
Target: C:\laragon\www\ngwe-lwe-system-laravel
```

## Purpose

This project is the PHP Laravel/Vue/MySQL version of the existing Python PyQt6/FastAPI/SQLite Ngwe Lwe System.

The product domain is money-transfer operations:

- Admin, cashier, and teller roles.
- Companies and service types.
- Accounts and balances.
- Cash In, Cash Out, Transfer, and Exchange transactions.
- Commission tiers and MMK fee rounding.
- Exchange rates.
- Main vault and teller cash float workflows.
- Cash denomination tracking.
- Daily summary, reconciliation, reports, and activity logs.
- Responsive desktop, tablet, and mobile UI.

## Current Migration Status

Started: 2026-07-01

Completed correct Ngwe Lwe Laravel foundation slices:

- Added Ngwe Lwe core schema migration based on `C:\laragon\www\ngwe-lwe-system\backend\database.sql`.
- Added user role/auth columns needed by the Python source system.
- Added core tables for companies, service types, accounts, transactions, commission tiers, exchange rates, cash floats, vault denominations, reconciliation, and activity logs.
- Seeded supported MMK note denominations, including `20,000`.
- Added `App\Support\Money` for money normalization, MMK fee rounding, and denomination total validation.
- Added PHPUnit tests for money behavior and schema expectations.
- Removed wrong Flight/Telemetry active code.
- Added Ngwe Lwe base models and repositories.
- Added HMAC bearer token auth foundation with username login, active-user checks, `auth_version`, and role middleware.
- Added authenticated CRUD API modules for companies, service types, and accounts.
- Added admin-only setup mutations and active-only listing by default.
- Added `CommissionTierRepository` and `TransactionFeeCalculator` for FIXED / PERCENTAGE commission and fee resolution, including MMK fee rounding.
- Added `AccountRepository::debitBalance` with `InsufficientBalanceException` guard so cash-in / transfer flows cannot overdraw the source account.
- Added admin-only `POST /api/accounts/{account}/balance-adjust` with `activity_logs` audit records via `App\Models\ActivityLog`.
- Added `App\Repositories\TransactionRepository` and `App\Services\TransactionService` for cash-in, cash-out, and transfer flows, using the calculator, balance guard, and activity log.
- Added money-movement API endpoints:
  - `GET /api/transactions`, `GET /api/transactions/recent`, `GET /api/transactions/{transaction}`.
  - `POST /api/transactions/cash-in` (pending cashier confirm), `POST /api/transactions/cash-out`, `POST /api/transactions/transfer`.
  - Cashier / admin: `POST /api/transactions/{transaction}/confirm-cash-in`, `POST /api/transactions/{transaction}/cancel-cash-in`.
  - `DELETE /api/transactions/{transaction}` — admin only, always 409 with the Python "hard delete disabled" guard.
- Added `App\Models\ExchangeRate`, `App\Repositories\ExchangeRateRepository`, and CRUD endpoints under `/api/exchange-rates` (admin writes, authenticated reads, `/latest` placeholder for empty state).
- Added `POST /api/transactions/exchange` that credits the source account using `sell_rate / base_amount` for MMK output and `buy_rate / base_amount` for THB output.
- Added `App\Models\CashFloatDenomination`, `App\Repositories\CashFloatRepository`, and `App\Services\CashFloatService` for the cash float lifecycle: `PENDING_RECEIPT → ACTIVE → PENDING_RECONCILIATION → CLOSED`.
- Added cash float API endpoints:
  - `GET /api/cash-floats`, `GET /api/cash-floats/{float}` (tellers scoped to their own).
  - Cashier / admin: `POST /api/cash-floats` (issue), `POST /api/cash-floats/{float}/confirm-return`.
  - Teller: `POST /api/cash-floats/{float}/activate`, `POST /api/cash-floats/{float}/initiate-return`.
- Added the main vault denomination ledger:
  - `App\Models\CashDenominationLog`, `App\Models\VaultDenominationBalance`, `App\Repositories\CashDenominationRepository` (records `vault_in` / `vault_out` / `float_returned` / `adjustment` entries with an atomic balance guard).
  - Float issue now debits the main vault via `vault_out`; float return confirmation credits the vault via `float_returned`.
  - `GET /api/vault/balance` and `GET /api/vault/inventory` for authenticated dashboard reads.
- Employee cash-out, transfer, and exchange are all denomination-aware:
  - `App\Services\EmployeeFloatValidator::validateFloatOperation` runs the same active-float / stock / total / balance checks before any writes.
  - `POST /api/transactions/cash-out`, `POST /api/transactions/transfer`, and `POST /api/transactions/exchange` accept an optional `denominations` map; when the creator is a teller, denominations are required and the float is atomically decremented per note and by total.
  - Insufficient float or note stock returns HTTP 409.
- Cash-in overpayment change flow: `POST /api/transactions/cash-in` accepts `amount_received` and `change_denominations`. When `amount_received > amount`, the change amount is drawn from the teller's active float (validated + deducted per note and by total). `change_given` and `change_denominations` are persisted on the transaction.
- PIN verification (bcrypt) is now enforced at float activation and cashier confirm-return:
  - `POST /api/auth/pin` — set or change the authenticated user's PIN (4–8 digits).
  - `POST /api/cash-floats/{float}/activate` and `.../confirm-return` now require a valid `pin` field.
  - Activation also requires `verified_denominations`; issued and counted quantities must match per MMK note before the float can move to `ACTIVE`.

- Reverb broadcast foundation is wired:
  - Private role channels: `admin`, `cashier`, `teller`.
  - Existing targeted private user channel: `user.{userId}`.
  - `balance_update`, `new_transaction`, `cash_in_pending`, `float_status_changed`, and `ping` events.
  - Owner-only `POST /api/broadcast/test` dispatches `ping`.
  - `resources/js/lib/echo.ts` provides the reusable Echo/Reverb helper for token-authenticated private channels.
- Vault transaction audit rows are wired:
  - `App\Models\VaultTransaction` and `App\Repositories\VaultTransactionRepository`.
  - One row per denomination quantity for float issue/receipt/return and teller cash draw operations.
  - Owner-only `GET /api/vault/log` is paginated and filterable.
- Float activation denomination re-verification is wired:
  - Teller-counted `verified_denominations` are compared with the issued float denominations after PIN verification and before the status transition.
  - Short-count and over-count attempts return HTTP 422 and leave the float in `PENDING_RECEIPT`.
- The Vue/Inertia frontend is now an operations console:
  - Dedicated `/login` page for sign-in, with token restore, logout, and PIN update handled outside the login form.
  - Admin, cashier, and teller role views for the completed setup, transaction, float, vault, exchange-rate, and realtime workflows.
  - Echo/Reverb role and user-channel subscriptions feed realtime events into the console.
- Owner-facing staff user management is wired:
  - Owner-only `/api/users` endpoints create, list, update, and deactivate staff accounts.
  - Role, password, username, and active-status changes revoke old tokens via `auth_version`.
  - The operations console now includes a Staff Users panel for creating, editing, and deactivating admin/cashier/teller users.
- Owner daily reporting and reconciliation are wired:
  - Owner-only `/api/reports/daily-summary`, `/api/reports/daily-reconciliation`, and `/api/reports/daily-reconciliations`.
  - Daily summaries include completed transaction totals, fees, profit, cash/digital snapshots, pending Cash In count, and vault/account/teller float snapshots.
  - The operations console now includes a Daily Report panel with refresh, Close Day, and recent reconciliation logs.
- MySQL verification is wired:
  - Local databases: `ngwe_lwe_laravel` and `ngwe_lwe_laravel_test`.
  - PHPUnit defaults to the MySQL test database.
  - DB-backed schema/auth/API/feature suites now run against MySQL instead of being skipped for missing `pdo_sqlite`.
- Demo seed data is wired for local walkthroughs:
  - Login users: `admin`, `cashier`, and `teller`.
  - Sample company, service types, accounts, commission tiers, exchange rate, and opening main-vault denominations.
  - The vault opening seed is idempotent and will not double-credit an already stocked vault.
- Local browser workflow QA has passed for the seeded roles:
  - Cashier issued a teller float.
  - Teller activated the float with PIN and denomination re-count.
  - Teller created a pending Cash In transaction.
  - Cashier confirmed the pending Cash In.
  - Reverb events arrived in the operations console without manual refresh.
- Docker startup hardening now validates required production env values before the app or Reverb starts.

Note: previous Flight/Telemetry work in this folder was a wrong starter and is not the product direction.

## Target Stack

| Layer | Technology |
| --- | --- |
| Backend | Laravel 13.x |
| PHP | PHP 8.4 recommended, minimum PHP 8.3 |
| Frontend | Vue 3.5 + Inertia 3 |
| Styling | Tailwind CSS 4.1 |
| Database | MySQL 8.0 or 8.4 LTS |
| Realtime | Laravel Reverb + Echo |
| Testing | PHPUnit, vue-tsc, ESLint, Vite build |

## Architecture Pattern

Database-backed business logic:

```text
Route -> Controller -> Request -> Service -> Repository -> Model
```

Pure calculation logic:

```text
Service -> Support Helper
```

Python to Laravel mapping:

| Python Source | Laravel Target |
| --- | --- |
| `backend/routes/*.py` | Controllers + routes |
| `repositories/*.py` | `app/Repositories` |
| `viewmodels/*.py` | `app/Services` |
| `models/*.py` | `app/Models` |
| `backend/money.py` | `app/Support/Money.php` |
| `backend/database.sql` | Laravel migrations |
| `views/*.py` | Vue/Inertia pages and components |

## Verification

Latest checks:

```bash
php artisan test
vendor/bin/pint --test
npm run types:check
npm run lint:check
npm run build
```

Current note: Laragon PHP has `pdo_mysql` enabled but not `pdo_sqlite`; PHPUnit is configured to use MySQL for DB-backed tests.

Current PHPUnit result: `121 tests, 121 passed, 589 assertions` against MySQL `ngwe_lwe_laravel_test`.

Local Laragon note: `.env` and `.env.example` use `CACHE_STORE=file` so the app can run without a Docker Redis hostname.

Docker setup is documented in [DOCKER.md](DOCKER.md).

Local QA note: Laragon's nginx may already occupy port `8080`; use Reverb port `8081` locally if needed. Docker still defaults to the documented Reverb host port unless changed in `.env`.

## Demo Credentials

Run `php artisan migrate --seed` locally, or `docker compose exec app php artisan db:seed --force` in Docker.

| Username | Password | PIN | Role |
| --- | --- | --- | --- |
| `admin` | `password123` | `1111` | Admin |
| `cashier` | `password123` | `2222` | Cashier |
| `teller` | `password123` | `3333` | Teller |

## Next Steps

1. Do a final Docker image build/run on the target machine before production use.
