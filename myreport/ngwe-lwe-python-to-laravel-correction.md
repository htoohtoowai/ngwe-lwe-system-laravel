# Ngwe Lwe Python To Laravel Correction Plan

Date: 2026-07-01

## Correction

The correct migration is:

```text
Source: C:\laragon\www\ngwe-lwe-system
Target: C:\laragon\www\ngwe-lwe-system-laravel
```

This is a Python/PyQt6/FastAPI/SQLite money-transfer system being rewritten as a PHP Laravel + Vue + MySQL responsive web application.

Flight Telemetry is not the target product. Any Flight/Telemetry code, documentation, tests, and UI currently in the Laravel target should be treated as a wrong starter and removed or archived before real Ngwe Lwe migration continues.

## Source System

Current source stack:

| Layer | Current Python System |
| --- | --- |
| Desktop UI | PyQt6 |
| Backend API | FastAPI |
| Database | SQLite |
| Real-time | FastAPI WebSocket |
| Auth | HMAC bearer token + WebSocket ticket |
| Password/PIN | bcrypt |
| Business Layer | ViewModels + Repositories |

Main source folders:

```text
backend/
models/
repositories/
services/
viewmodels/
views/
i18n/
tests/
```

## Target Stack

Use this target stack:

| Layer | Laravel Target |
| --- | --- |
| Backend | Laravel 13.x |
| PHP | PHP 8.4 recommended, minimum 8.3 |
| Frontend | Vue 3.5 + Inertia 3 |
| Styling | Tailwind CSS 4.1 |
| Database | MySQL 8.0 or 8.4 LTS |
| Realtime | Laravel Reverb + Echo |
| Auth | Laravel session/auth or Sanctum-style API auth |
| Password/PIN | Laravel Hash facade |
| Testing | PHPUnit + vue-tsc + Vite build |

## Correct Domain Modules

The Laravel project must focus on these Ngwe Lwe modules:

- Auth, roles, and users: owner, cashier, employee.
- Companies.
- Service types.
- Accounts and balances.
- Commission tiers and fee calculation.
- Exchange rates.
- Transactions: cash in, cash out, transfer, exchange.
- Cashier workflows.
- Main vault denominations.
- Employee cash float lifecycle.
- Cash In pending cashier confirmation.
- Daily summary and reconciliation.
- Activity logs.
- Myanmar/English UI text.
- Desktop, tablet, mobile responsive dashboard and forms.

## Backend Pattern

Use Repository pattern for database-backed business operations:

```text
Route -> Controller -> Request -> Service -> Repository -> Model
```

Use Service/Support helpers for pure calculation:

```text
Service -> Support Helper
```

Important PHP mappings:

| Python Source | Laravel Target |
| --- | --- |
| `backend/routes/*.py` | `app/Http/Controllers` + `routes/api.php` / `routes/web.php` |
| `repositories/*.py` | `app/Repositories` |
| `viewmodels/*.py` | `app/Services` |
| `models/*.py` | `app/Models` |
| `backend/money.py` | `app/Support/Money.php` |
| `backend/websocket_manager.py` | Laravel Events + Reverb channels |
| `backend/database.sql` | Laravel migrations |
| `views/*.py` | Vue/Inertia pages and components |

## First Real Implementation Order

1. Stop using Flight/Telemetry as the implementation base.
2. Preserve a backup of the current mistaken Laravel folder if needed.
3. Create or reset `ngwe-lwe-system-laravel` as the Ngwe Lwe Laravel target.
4. Port database schema from `backend/database.sql` to Laravel migrations.
5. Port core enums/constants for roles, transaction status, float status, and denominations.
6. Port pure money calculation first:
   - `normalize_money`
   - MMK fee rounding
   - denomination total validation
7. Add PHPUnit tests matching the existing Python tests.
8. Port users/auth and role guards.
9. Port companies, service types, accounts.
10. Port commission tiers and exchange rates.
11. Port cash float and vault workflows.
12. Port transaction workflows.
13. Build Vue/Inertia responsive screens for owner, cashier, and employee.

## Immediate Cleanup Needed

Remove or archive these wrong Flight/Telemetry concepts from the target Laravel project:

- Flight dashboard.
- Telemetry parser.
- TCP telemetry client.
- `fts.onenex.dev` config.
- `FlightController`, `FlightResource`, `FlightCard`.
- `/api/flights`.
- `TelemetryUpdated`.
- Flight/telemetry tests and docs.

Replace them with Ngwe Lwe money-transfer modules.

