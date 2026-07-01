# Change Note: Ngwe Lwe System Laravel Migration

Date: 2026-07-01

## Correct Direction

The project conversion is:

```text
Source: C:\laragon\www\ngwe-lwe-system
Target: C:\laragon\www\ngwe-lwe-system-laravel
```

This is a Python/PyQt6/FastAPI/SQLite money-transfer system being rewritten as a Laravel + Vue + MySQL responsive web application.

Flight/Telemetry work was a wrong starter and is no longer active product code.

## Target Stack

| Layer | Technology |
| --- | --- |
| Backend | Laravel 13.x |
| PHP | PHP 8.4 recommended, minimum PHP 8.3 |
| Frontend | Vue 3.5 + Inertia 3 |
| Styling | Tailwind CSS 4.1 |
| Database | MySQL 8.0 or 8.4 LTS |
| Realtime | Laravel Reverb + Echo |
| Testing | PHPUnit, Pint, vue-tsc, ESLint, Vite build |

## Domain Modules

- Users and roles: owner, cashier, employee.
- Companies.
- Service types.
- Accounts and balances.
- Commission tiers and fee calculation.
- Exchange rates.
- Transactions: Cash In, Cash Out, Transfer, Exchange.
- Cashier workflows.
- Main vault denominations.
- Employee cash float lifecycle.
- Cash In pending cashier confirmation.
- Daily summary and reconciliation.
- Activity logs.
- Myanmar/English UI text.
- Desktop, tablet, and mobile responsive UI.

## Pattern

Use this for database-backed business workflows:

```text
Route -> Controller -> Request -> Service -> Repository -> Model
```

Use this for pure calculation:

```text
Service -> Support Helper
```

## Slice 1: Schema And Money Foundation

Completed:

- Added Ngwe Lwe core schema migration from Python `backend/database.sql`.
- Added Laravel user fields for `username`, `pin_hash`, `full_name`, `role`, `is_active`, and `auth_version`.
- Added core tables for companies, service types, accounts, transactions, commission tiers, exchange rates, cash floats, vault denominations, reconciliation, and activity logs.
- Seeded supported MMK denominations: `50`, `100`, `200`, `500`, `1000`, `5000`, `10000`, `20000`.
- Added `App\Support\Money` for money normalization, MMK fee rounding, and denomination totals.
- Added tests for money rules and schema expectations.
- Rewrote `README.md` and `report.md` to Ngwe Lwe migration direction.

## Slice 2: Remove Wrong Flight Code And Add Domain Foundation

Completed:

- Removed active Flight/Telemetry routes, provider bindings, commands, services, events, frontend components, types, and tests.
- Replaced `/api/flights` with `/api/system/status`.
- Replaced public `flight.{id}` channel with authenticated `user.{userId}` channel placeholder.
- Removed browser `flightTelemetryConfig` injection.
- Replaced the old flight dashboard with a Ngwe Lwe migration dashboard shell.
- Rewrote responsive CSS for Ngwe Lwe modules.
- Updated `User` model and factory for Ngwe Lwe auth/role fields.
- Added base models:
  - `Company`
  - `ServiceType`
  - `Account`
  - `Transaction`
  - `CommissionTier`
  - `CashFloatAssignment`
- Added base repositories:
  - `CompanyRepository`
  - `ServiceTypeRepository`
  - `AccountRepository`
  - `UserRepository`
- Added `NgweLweModelTest`.
- Refreshed Composer autoload after deleting wrong Flight classes.

## Verification Commands

```bash
php artisan test
vendor/bin/pint --test
npm run types:check
npm run lint:check
npm run build
composer validate --no-check-publish
```

## Known Notes

- `pdo_sqlite` is not enabled in current Laragon PHP, so in-memory SQLite schema tests are skipped.
- MySQL test database verification is still needed.
- Composer in Laragon is old/noisy under PHP 8.4 and emits deprecation notices, but validation passes.
- Existing vendor dependency advisories were reported by Composer and are not introduced by this slice.

## Next Slice: Auth And Roles Foundation

Goal: port the Python Ngwe Lwe login/role behavior into Laravel first, with MySQL verification as the setup step.

Completed:

- Removed deleted telemetry command registration from `bootstrap/app.php`.
- Added `config/ngwe_lwe.php` for auth token settings.
- Added `.env.testing.example`.
- Updated `.env.example` with MySQL database defaults and Ngwe Lwe auth env values.
- Added HMAC bearer token foundation matching the Python payload style:
  - `user_id`
  - `username`
  - `role`
  - `auth_version`
  - `exp`
- Added `NgweLweTokenService`.
- Added `AuthController` with:
  - `POST /api/auth/login`
  - `GET /api/auth/me`
  - `POST /api/auth/logout`
- Added `ngwe.auth` middleware for bearer token auth.
- Added `role` middleware for owner/cashier/employee checks.
- Added protected role status routes:
  - `GET /api/owner/status`
  - `GET /api/cashier/status`
  - `GET /api/employee/status`
- Added user repository methods for username lookup and auth revocation.
- Added token service unit tests.
- Added DB-backed auth feature tests; these skip until `pdo_sqlite` or MySQL testing is available.
- Changed local cache defaults from Redis to file cache for Laragon development.
- Restarted the local server on `http://127.0.0.1:8001` and verified `/api/system/status`.

Verification passed:

```bash
php artisan route:list --path=api
php artisan test
vendor/bin/pint --test
npm run types:check
npm run lint:check
npm run build
Invoke-WebRequest http://127.0.0.1:8001/api/system/status
```

Current PHPUnit result:

```text
17 tests, 11 passed, 6 skipped, 33 assertions
```

Skipped tests are DB-backed schema/auth tests waiting for SQLite extension or MySQL test database setup.

## Next Slice: Companies, Service Types, And Accounts API

Goal: start the first real Ngwe Lwe CRUD/API modules on top of the auth/role foundation.

Completed:

- Added CRUD repositories for:
   - Companies
   - Service Types
   - Accounts
- Added Form Request validation for:
  - `CompanyRequest`
  - `ServiceTypeRequest`
  - `AccountRequest`
- Added API resources for stable JSON output:
  - `CompanyResource`
  - `ServiceTypeResource`
  - `AccountResource`
- Added API controllers:
  - `CompanyController`
  - `ServiceTypeController`
  - `AccountController`
- Added authenticated read endpoints:
  - `GET /api/companies`
  - `GET /api/companies/{company}`
  - `GET /api/service-types`
  - `GET /api/service-types/{serviceType}`
  - `GET /api/accounts`
  - `GET /api/accounts/{account}`
- Added owner-only setup endpoints:
  - `POST /api/companies`
  - `PATCH /api/companies/{company}`
  - `DELETE /api/companies/{company}`
  - `POST /api/service-types`
  - `PATCH /api/service-types/{serviceType}`
  - `DELETE /api/service-types/{serviceType}`
  - `POST /api/accounts`
  - `PATCH /api/accounts/{account}`
  - `DELETE /api/accounts/{account}`
- Ported soft-deactivate behavior for delete routes.
- Default list endpoints return active records only; `include_inactive=true` can include inactive records.
- Added account filters for `company_id`, `service_type_id`, and `fee_only`.
- Normalized account `balance` and `commission_rate` through `App\Support\Money`.
- Added skip-safe feature tests for setup API create/list/role/deactivate flows.

Verification passed:

```bash
php artisan route:list --path=api
php artisan test
vendor/bin/pint --test app/Repositories app/Http/Controllers/Api app/Http/Requests app/Http/Resources routes/api.php tests/Feature/NgweLweSetupApiTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
```

Current PHPUnit result:

```text
21 tests, 11 passed, 10 skipped, 33 assertions
```

Skipped tests are DB-backed schema/auth/setup API tests waiting for SQLite extension or MySQL test database setup.

## Next Slice: Commission Tiers, Fees, And Balance Guards

Goal: port calculation-sensitive business logic before building transaction UI, because the user specifically noted wrong calculations.

Completed:

- Added `CommissionTierRepository::findForAmount` that ports the Python
  `get_tier_for_amount` half-open range query, including the "catch-all"
  (1 to >= 999_999_999_999) tier being de-prioritized behind specific tiers.
- Added `App\Services\TransactionFeeCalculator` that ports Python
  `_resolve_fee_values`, `_calc_commission`, and `_calc_amount_by_type`:
  - FIXED vs PERCENTAGE resolution for base fee, additional fee, and commission.
  - MMK fee rounding for `customer_fee` (base + additional) via
    `Money::roundMmkFee`.
  - Cash-in vs cash-out uses deposit vs withdraw columns.
  - Commission `send` reads `comm_deposit`, `receive` reads `comm_withdraw`.
- Added `AccountRepository::incrementBalance` (mirrors Python
  `increment_balance`) and `AccountRepository::debitBalance` with an
  `InsufficientBalanceException` guard so cash-in / transfer flows cannot
  overdraw the source account.
- Added `ActivityLog` model bound to the existing `activity_logs` table.
- Added owner-only endpoint `POST /api/accounts/{account}/balance-adjust`:
  - Validated by `BalanceAdjustRequest` (`amount`, `remark`).
  - Normalizes the delta through `Money::normalize`.
  - Atomically applies the delta and writes an `activity_logs` row with
    `old_balance`, `new_balance`, `amount`, and `remark`.
- Added tests:
  - `TransactionFeeCalculatorTest` (unit, no DB) covers no-tier zeroes,
    FIXED fee, PERCENTAGE fee + MMK rounding, cash-out withdraw column,
    and FIXED/PERCENTAGE commission.
  - `CommissionTierAndBalanceTest` (feature, `RefreshDatabase`) covers
    tier lookup priority, DB-backed calculator behavior, balance debit
    overdraw rejection, successful debit, balance-adjust activity log,
    and owner-only enforcement.

Notes:

- Balance-adjust mirrors Python and does not itself guard against a
  resulting negative balance; the guard is applied on debit flows because
  Python allows owners to freely correct balances via the audited route.
- Transaction endpoint wiring (cash-in / cash-out / transfer / exchange)
  is not part of this slice; only the calculation primitives and
  balance-guard helpers are landed here.

Verification passed in this slice:

```bash
php artisan test
vendor/bin/pint --test app/Repositories/CommissionTierRepository.php \
  app/Repositories/AccountRepository.php \
  app/Services/TransactionFeeCalculator.php \
  app/Exceptions/InsufficientBalanceException.php \
  app/Models/ActivityLog.php \
  app/Http/Requests/BalanceAdjustRequest.php \
  app/Http/Controllers/Api/AccountController.php \
  routes/api.php \
  tests/Unit/TransactionFeeCalculatorTest.php \
  tests/Feature/CommissionTierAndBalanceTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
```

Current PHPUnit result:

```text
33 tests, 17 passed, 16 skipped, 45 assertions
```

The 6 new `TransactionFeeCalculatorTest` unit tests pass. The 6 new
`CommissionTierAndBalanceTest` cases and other DB-backed tests remain
skipped until `pdo_sqlite` or MySQL test database is enabled in Laragon
PHP. Pint on all files touched in this slice passes; a pre-existing
`app/Http/Controllers/Controller.php` empty-body warning is not part of
this slice.

## Next Slice: Transaction Endpoints (Cash In / Cash Out / Transfer)

Goal: land the base money-movement API on top of the calculator, balance
guard, and activity log primitives from the previous slice.

Completed:

- Added `App\Repositories\TransactionRepository` with create, find,
  filter, recent, `confirmPendingCashIn`, and `cancelPendingCashIn`.
- Added `App\Services\TransactionService`:
  - `createCashIn` debits the source account, writes a
    `PENDING_CASHIER_CONFIRM` transaction with `vault_impact = 'none'`,
    and logs the creation. Fee / commission come from the calculator.
  - `createCashOut` credits the source account, writes a `COMPLETED`
    transaction, and credits the fee account (matches Python
    `_update_fee_account`).
  - `createTransfer` debits the source with the balance guard,
    credits the target, and writes a `COMPLETED` transfer transaction.
  - `confirmPendingCashIn` transitions the transaction to
    `COMPLETED` / `vault_impact = 'main_vault_increase'` and stamps
    `confirmed_by`, `confirmed_at`, `cash_approved_by`, and
    `cash_approved_at` (mirrors Python `confirm_pending_cash_in`).
  - `cancelPendingCashIn` reverses the balance debit, sets
    `CANCELLED` / `vault_impact = 'none'`, and stores the cashier note
    if provided.
- Added Form Requests: `CashInRequest`, `CashOutRequest`,
  `TransferRequest`, `CancelCashInRequest`.
- Added `TransactionResource` for consistent JSON output.
- Added `TransactionController`:
  - `GET /api/transactions` — owner sees all filtered by
    `date_from`, `date_to`, `type`, `account_id`, `limit`; employee
    sees only their own.
  - `GET /api/transactions/recent` — respects role scoping.
  - `GET /api/transactions/{transaction}` — auth read.
  - `POST /api/transactions/cash-in` — owner/employee (cashier is
    blocked from creating), returns 409 on overdraw.
  - `POST /api/transactions/cash-out` — owner/employee only.
  - `POST /api/transactions/transfer` — owner/employee only, 409 on
    overdraw, 422 on same-account.
  - `POST /api/transactions/{transaction}/confirm-cash-in` — cashier
    or owner only, 409 if not pending.
  - `POST /api/transactions/{transaction}/cancel-cash-in` — cashier
    or owner only, reverses balance.
  - `DELETE /api/transactions/{transaction}` — owner only, 409 with
    the disabled-hard-delete message (matches Python).
- Added `TransactionEndpointsTest` feature suite with 11 cases
  covering: cash-in pending semantics, cash-in overdraw rejection,
  cash-out balance credit, transfer balance movement + overdraw
  rejection, same-account rejection, cashier create block, cashier
  confirm, cashier cancel + reversal, confirm idempotency,
  owner filtered listing, and disabled hard delete.

Out of scope (deferred to a later slice):

- Employee cash float and vault denomination validation.
- WebSocket balance / new-transaction broadcasts.

## Next Slice: Exchange Rates And Exchange Transactions

Goal: port the currency conversion feature (Python
`repositories/exchange_rate_repository.py`,
`backend/routes/exchange_rates.py`, and
`repositories/exchange_repository.py`).

Completed:

- Added `App\Models\ExchangeRate` with decimal casts for
  `base_amount`, `buy_rate`, and `sell_rate`.
- Added `App\Repositories\ExchangeRateRepository`:
  - `getLatest($base, $quote)`
  - `recent($limit)` clamped to `[1, 200]`
  - `find`, `create`, `update`, `delete`
- Added `App\Http\Requests\ExchangeRateRequest` (up-cases currency
  codes) and `App\Http\Resources\ExchangeRateResource`.
- Added `App\Http\Controllers\Api\ExchangeRateController`:
  - `GET /api/exchange-rates` — authenticated recent list.
  - `GET /api/exchange-rates/latest?base=&quote=` — returns the
    stored rate, or a zero placeholder mirroring the Python
    `/latest` fallback response.
  - `GET /api/exchange-rates/{exchangeRate}` — authenticated read.
  - Owner-only: `POST`, `PATCH`, `DELETE`.
- Added `App\Http\Requests\ExchangeRequest` (validates
  `currency in [MMK, THB]` and normalises the case).
- Extended `App\Services\TransactionService` with `createExchange`:
  - Loads latest `THB→MMK` rate; 422 if missing.
  - Computes `exchange_rate = sell_rate / base_amount` for MMK
    output and `buy_rate / base_amount` for THB output, matching
    Python `ExchangeRepository.create`.
  - Uses `TransactionFeeCalculator` on the source account and
    credits the account like cash-out.
  - Credits the fee account and writes an `activity_logs` audit row.
- Added `POST /api/transactions/exchange` on `TransactionController`,
  cashier-blocked and 422 on invalid currency / missing rate.
- Added `ExchangeRateAndTransactionTest` covering:
  - Owner CRUD (create + partial patch, up-cases the currency).
  - Employee forbidden from create.
  - `/latest` placeholder response when no rate is stored and stored
    response when one exists.
  - Exchange transaction credits the account for MMK, uses `sell_rate`
    for MMK and `buy_rate` for THB.
  - `base_amount` divisor is respected (rate `1480 / 10 = 148`).
  - Rejects unsupported currency and missing rate with 422.
  - Cashier blocked from creating exchange transactions.

Verification passed in this slice:

```bash
php artisan test
vendor/bin/pint --test app/Models/ExchangeRate.php \
  app/Repositories/ExchangeRateRepository.php \
  app/Http/Requests/ExchangeRateRequest.php \
  app/Http/Requests/ExchangeRequest.php \
  app/Http/Resources/ExchangeRateResource.php \
  app/Http/Controllers/Api/ExchangeRateController.php \
  app/Http/Controllers/Api/TransactionController.php \
  app/Services/TransactionService.php \
  routes/api.php \
  tests/Feature/ExchangeRateAndTransactionTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
php artisan route:list --path=api
```

Current PHPUnit result:

```text
54 tests, 17 passed, 37 skipped, 45 assertions
```

The 10 new `ExchangeRateAndTransactionTest` cases are DB-backed and
remain skipped until `pdo_sqlite` (or a MySQL test database) is
enabled in Laragon PHP.

## Next Slice: Cash Float Lifecycle

Goal: port the cash float assignment lifecycle from Python
`repositories/cash_float_repository.py` and the cashier route
handlers, without the vault denomination log integration (deferred to
the vault slice).

Completed:

- Added `App\Models\CashFloatDenomination` and a `denominations`
  HasMany on `App\Models\CashFloatAssignment`.
- Added `App\Repositories\CashFloatRepository` with:
  - `find`, `list`, `activeForEmployee`, `pendingForEmployee`
  - `issue` — inserts a `PENDING_RECEIPT` float plus denomination rows
    in one DB transaction, using `Money::denominationTotal` for the
    total.
  - `activate` — `PENDING_RECEIPT → ACTIVE`, sets
    `received_at` and `current_balance = total_amount` via
    `DB::raw`, matches Python `activate_float_v2` (guards concurrent
    activation).
  - `initiateReturn` — `ACTIVE → PENDING_RECONCILIATION` with
    `return_denominations_json`.
  - `confirmReturn` — `PENDING_RECONCILIATION → CLOSED`, sets
    `closed_at`, `closing_total`, and zeroes `current_balance`.
  - `deductBalance` — atomic `current_balance` deduction with an
    `InsufficientFloatException` guard, mirroring Python
    `deduct_float_balance`.
- Added `App\Exceptions\InsufficientFloatException`.
- Added `App\Services\CashFloatService` that wraps the repository
  with role-safe entry points and writes `activity_logs` audit rows:
  - `issue` — cashier action, `float_issued`.
  - `activate` — employee action, must own the float,
    `float_activated`.
  - `initiateReturn` — employee action, validates denominations via
    `Money::denominationTotal`, `float_return_initiated`.
  - `confirmReturn` — cashier action, `float_return_confirmed`.
- Added Form Requests:
  - `IssueCashFloatRequest` — validates `employee_id` exists as an
    active employee and denominations are supported MMK notes.
  - `InitiateFloatReturnRequest` — validates the return-denomination
    map is non-empty and all keys are supported notes.
  - `ConfirmFloatReturnRequest` — validates `closing_total`.
- Added `CashFloatResource` (includes denominations, employee name,
  issuer name).
- Added `CashFloatController` with:
  - `GET /api/cash-floats` — authenticated list; employees see only
    their own floats.
  - `GET /api/cash-floats/{float}` — authenticated read; employees
    forbidden on other employees' floats.
  - `POST /api/cash-floats` — cashier or owner only, 422 on invalid
    denominations, 201 with float payload.
  - `POST /api/cash-floats/{float}/activate` — employee only, 403 if
    the float belongs to a different employee, 409 if not
    `PENDING_RECEIPT`.
  - `POST /api/cash-floats/{float}/initiate-return` — employee only,
    409 if not `ACTIVE`.
  - `POST /api/cash-floats/{float}/confirm-return` — cashier or owner
    only, 409 if not `PENDING_RECONCILIATION`.
- Added explicit `Route::model('float', CashFloatAssignment::class)`
  binding so the `{float}` URL parameter resolves cleanly.
- Added `CashFloatLifecycleTest` covering: cashier issue with total
  computation and activity log, unsupported-denomination rejection,
  employee cannot issue, employee activates own float and
  `current_balance` matches `total_amount`, employee cannot activate
  another employee's float, double-activation returns 409, full
  lifecycle end-to-end with activity log per state transition,
  initiate-return requires `ACTIVE`, employee list scoping, and
  employee cannot view another employee's float.

Out of scope (deferred to a later slice):

- Vault denomination log integration (`record_bulk_entry` for
  `vault_out`, `vault_in`, `float_returned`).
- Wiring float and denomination checks into cash-in / cash-out /
  transfer / exchange flows.
- WebSocket balance / new-transaction broadcasts.

Verification passed in this slice:

```bash
php artisan test
vendor/bin/pint --test app/Models/CashFloatAssignment.php \
  app/Models/CashFloatDenomination.php \
  app/Repositories/CashFloatRepository.php \
  app/Services/CashFloatService.php \
  app/Exceptions/InsufficientFloatException.php \
  app/Http/Requests/IssueCashFloatRequest.php \
  app/Http/Requests/InitiateFloatReturnRequest.php \
  app/Http/Requests/ConfirmFloatReturnRequest.php \
  app/Http/Resources/CashFloatResource.php \
  app/Http/Controllers/Api/CashFloatController.php \
  routes/api.php \
  tests/Feature/CashFloatLifecycleTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
php artisan route:list --path=api
```

Current PHPUnit result:

```text
64 tests, 17 passed, 47 skipped, 45 assertions
```

The 10 new `CashFloatLifecycleTest` cases are DB-backed and remain
skipped until `pdo_sqlite` (or a MySQL test database) is enabled.

## Next Slice: Vault Denomination Ledger

Goal: port the main-vault denomination ledger from Python
`repositories/cash_denomination_repository.py` and wire the float
lifecycle to the ledger like `services/vault_service.py`.

Completed:

- Added `App\Models\CashDenominationLog` (`cash_denomination_logs`
  table).
- Added `App\Models\VaultDenominationBalance`
  (`vault_denomination_balances` table, `denomination_id` primary
  key, no timestamps).
- Added `App\Exceptions\InsufficientVaultDenominationException`.
- Added `App\Repositories\CashDenominationRepository`:
  - `recordBulk($entryType, $denominations, $createdBy, $floatId,
    $transactionId, $note)` — writes one `cash_denomination_logs`
    row per denomination and atomically applies the delta to
    `vault_denomination_balances`. `vault_out` throws
    `InsufficientVaultDenominationException` if the result would
    go negative; `vault_in`, `float_returned`, and `adjustment`
    credit the vault.
  - `getVaultBalance()` — returns the net per denomination from
    the ledger (`vault_in + float_returned + adjustment -
    vault_out`).
  - `getPendingReserved()` — sums denominations held by
    `PENDING_RECEIPT` floats (kept for parity with Python; zero in
    normal operation because `vault_out` already covers pending).
  - `getAvailableBalance()` — `vault - pending`, floored at 0.
  - `recentLogs($limit)` — recent ledger entries.
- Extended `App\Services\CashFloatService`:
  - `issue` now writes a `vault_out` ledger entry alongside the
    activity log, so the main vault is debited at issuance.
    Insufficient vault stock rejects the whole issue transaction.
  - `confirmReturn` now writes a `float_returned` ledger entry
    when a return-denomination breakdown is present, crediting the
    main vault.
- Added `App\Http\Controllers\Api\VaultController`:
  - `GET /api/vault/balance` — vault, pending, available maps plus
    totals.
  - `GET /api/vault/inventory` — main vault + open floats
    (`PENDING_RECEIPT`, `ACTIVE`, `PENDING_RECONCILIATION`)
    with per-denomination totals and a grand physical total.
- Updated `CashFloatLifecycleTest::test_cashier_can_issue_float_
  with_denominations_and_computed_total` to seed the vault before
  issuing a float (issue now guards on vault stock). Added a
  `seedVaultBalance` helper that wraps
  `CashDenominationRepository::recordBulk('vault_in', ...)`.
- Added `VaultLedgerTest` covering: net balance from mixed
  entry types, negative-vault rejection on `vault_out`, float
  issue writes `vault_out` + decrements balance, float issue fails
  when vault stock is insufficient, `confirmReturn` writes
  `float_returned` + credits vault, `/vault/balance` totals,
  `/vault/inventory` includes open floats, and closed floats are
  excluded from `/vault/inventory`.

Out of scope (deferred to a later slice):

- PIN verification for cashier / employee vault operations.
- `VaultService.validate_cash_out` / `process_cash_out`
  denomination checks inside cash-in / cash-out / transfer /
  exchange transaction flows.
- `vault_transactions` audit log rows.
- Reverb WebSocket broadcasts for balance and new-transaction
  events.

Verification passed in this slice:

```bash
php artisan test
vendor/bin/pint --test app/Models/CashDenominationLog.php \
  app/Models/VaultDenominationBalance.php \
  app/Repositories/CashDenominationRepository.php \
  app/Services/CashFloatService.php \
  app/Exceptions/InsufficientVaultDenominationException.php \
  app/Http/Controllers/Api/VaultController.php \
  routes/api.php \
  tests/Feature/CashFloatLifecycleTest.php \
  tests/Feature/VaultLedgerTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
php artisan route:list --path=api
```

Current PHPUnit result:

```text
72 tests, 17 passed, 55 skipped, 45 assertions
```

The 8 new `VaultLedgerTest` cases are DB-backed and remain skipped
until `pdo_sqlite` (or a MySQL test database) is enabled.

## Next Slice: Employee Denomination-Aware Cash Out

Goal: port the read-side validation and write-side denomination
deduction from Python `services/vault_service.py` (validate_cash_out
/ process_cash_out) into the Laravel cash-out endpoint, matching the
Python rule that only employee-initiated cash-out interacts with the
float.

Completed:

- Extended `App\Repositories\CashFloatRepository` with:
  - `getDenominationBalance($floatId)` — per-denomination quantities
    for a float.
  - `deductDenominations($floatId, $denominations)` — atomic per-row
    UPDATE guarded by `WHERE quantity >= ?`; throws
    `RuntimeException` if a denomination is exhausted (mirrors
    Python `deduct_denominations`).
- Added `App\Exceptions\InsufficientFloatDenominationException`
  (409 in the API).
- Added `App\Services\EmployeeFloatValidator::validateCashOut`
  (pure validation, no writes):
  - Normalizes the denomination map (positive quantities only,
    supported notes only).
  - Requires an active float belonging to the employee.
  - Checks per-denomination stock and throws
    `InsufficientFloatDenominationException` on shortfall.
  - Checks the denomination total matches the amount within 1 MMK.
  - Checks `current_balance` covers the total; throws
    `InsufficientFloatException` on shortfall.
- Updated `App\Services\TransactionService::createCashOut`:
  - When `creator->role === 'employee'`, runs the validator before
    any writes.
  - Inside the DB transaction, deducts denominations via
    `deductDenominations` and float balance via `deductBalance`.
  - Owner / cashier cash-out flow unchanged (matches Python).
  - Constructor now takes `CashFloatRepository` and
    `EmployeeFloatValidator`.
- Extended `App\Http\Requests\CashOutRequest`:
  - Accepts optional `denominations` map with per-key `integer|min:0`
    validation and a `withValidator` supported-note check.
- Updated `App\Http\Controllers\Api\TransactionController::cashOut`
  to translate `InsufficientFloatException` and
  `InsufficientFloatDenominationException` to HTTP 409.
- Added `EmployeeCashOutFloatTest` (6 cases):
  - Employee cash-out deducts float denominations and balance.
  - Employee cash-out requires denominations (422 without).
  - Employee cash-out rejects without an active float (422).
  - Employee cash-out rejects when denom total ≠ amount (422).
  - Employee cash-out rejects when a denomination is out of stock
    (409, float unchanged).
  - Owner cash-out still works without any denominations.

Out of scope (deferred to a later slice):

- Denomination breakdown on transfer and exchange endpoints for
  employees (same pattern applies).
- Cash-in overpayment change flow that draws change denominations
  from the employee float.
- PIN verification for cashier receipt-confirm and float-return
  flows.
- Reverb WebSocket balance / new-transaction broadcasts.

Verification passed in this slice:

```bash
php artisan test
vendor/bin/pint --test app/Repositories/CashFloatRepository.php \
  app/Services/EmployeeFloatValidator.php \
  app/Services/TransactionService.php \
  app/Exceptions/InsufficientFloatDenominationException.php \
  app/Http/Requests/CashOutRequest.php \
  app/Http/Controllers/Api/TransactionController.php \
  tests/Feature/EmployeeCashOutFloatTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
```

Current PHPUnit result:

```text
78 tests, 17 passed, 61 skipped, 45 assertions
```

The 6 new `EmployeeCashOutFloatTest` cases are DB-backed and remain
skipped until `pdo_sqlite` (or a MySQL test database) is enabled.

## Next Slice: Denomination-Aware Transfer + Exchange

Goal: extend the employee-float denomination pattern that already
covers cash-out to also cover transfer and exchange, matching Python
`transfer_repository.py` and `exchange_repository.py` (both call
`_validate_employee_float`).

Completed:

- Renamed `App\Services\EmployeeFloatValidator::validateCashOut` to
  `validateFloatOperation($employeeId, $denominations, $amount,
  $operationLabel)`. The `operationLabel` argument only affects the
  human-readable error message; the validation logic is unchanged
  and is identical for cash-out, transfer, and exchange.
- Updated `App\Services\TransactionService`:
  - `createCashOut` now calls `validateFloatOperation(..., 'cash-out')`.
  - `createTransfer` calls `validateFloatOperation(..., 'transfer')`
    when the creator is an employee, and, inside the DB transaction,
    deducts denominations via `deductDenominations` and the float
    balance via `deductBalance` after the source-account debit and
    target-account credit.
  - `createExchange` calls `validateFloatOperation(..., 'exchange')`
    when the creator is an employee, and applies the same float
    deductions after crediting the source account.
- Extended `App\Http\Requests\TransferRequest` and
  `App\Http\Requests\ExchangeRequest` to accept an optional
  `denominations` map with per-key `integer|min:0` validation and a
  `withValidator` supported-note check.
- Updated `App\Http\Controllers\Api\TransactionController::transfer`
  and `exchange` to translate `InsufficientFloatException` and
  `InsufficientFloatDenominationException` to HTTP 409.
- Added `EmployeeTransferExchangeFloatTest` (7 cases):
  - Employee transfer deducts float denominations and balance.
  - Employee transfer without denominations rejected (422).
  - Employee transfer with insufficient note stock rejected (409),
    accounts and float unchanged.
  - Owner transfer still works without denominations.
  - Employee exchange deducts float denominations and balance,
    exchange rate applied.
  - Employee exchange without denominations rejected (422).
  - Employee exchange with denom-total ≠ amount rejected (422).

Out of scope (deferred to a later slice):

- Cash-in overpayment change flow that draws change denominations
  from the employee float (Python
  `create_cash_in`'s `change_due` / `change_denoms` path).
- PIN verification for cashier receipt-confirm and float-return
  flows.
- `vault_transactions` audit rows for each cash-out / transfer /
  exchange denomination movement.
- Reverb WebSocket balance / new-transaction broadcasts.

Verification passed in this slice:

```bash
php artisan test
vendor/bin/pint --test app/Services/EmployeeFloatValidator.php \
  app/Services/TransactionService.php \
  app/Http/Requests/TransferRequest.php \
  app/Http/Requests/ExchangeRequest.php \
  app/Http/Controllers/Api/TransactionController.php \
  tests/Feature/EmployeeTransferExchangeFloatTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
```

Current PHPUnit result:

```text
85 tests, 17 passed, 68 skipped, 45 assertions
```

The 7 new `EmployeeTransferExchangeFloatTest` cases are DB-backed and
remain skipped until `pdo_sqlite` (or a MySQL test database) is
enabled.

## Next Slice: Cash-In Overpayment Change

Goal: port Python `cash_in_repository.py` overpayment change path so
employees can hand back change from their float when the customer pays
more than the digital amount.

Completed:

- Extended `App\Http\Requests\CashInRequest` with optional
  `amount_received` (numeric, min 0) and `change_denominations`
  (array of denomination → integer qty, supported-note check).
- Updated `App\Services\TransactionService::createCashIn`:
  - Normalises `amount_received` (defaults to `amount` when absent).
  - Rejects `amount_received < amount` with 422.
  - Computes `change_due = amount_received - amount` and rejects
    `change_denominations` when `change_due == 0`.
  - When `change_due > 0`:
    - Rejects non-employee creators with 422 (only employees have
      an active float to draw change from — matches Python).
    - Runs `EmployeeFloatValidator::validateFloatOperation(...,
      'cash-in overpayment change')` on the change amount.
    - Inside the DB transaction (after digital debit and before the
      activity log): deducts denominations via
      `CashFloatRepository::deductDenominations` and float
      `current_balance` via `deductBalance` for `change_due`.
    - Stores `change_given` and `change_denominations` on the
      transaction row and writes a
      `cash_in_overpayment_change_given` activity log entry.
- Updated `TransactionController::cashIn` to translate
  `InsufficientFloatException` and
  `InsufficientFloatDenominationException` to HTTP 409.
- Added `CashInOverpaymentTest` (7 cases):
  - No overpayment leaves the float untouched (still
    `PENDING_CASHIER_CONFIRM`, `change_given` = `0.00`).
  - Overpayment deducts change from the float and stores the
    change denomination map on the transaction.
  - Change denomination total ≠ change_due rejected (422).
  - `amount_received < amount` rejected (422).
  - `change_denominations` without overpayment rejected (422).
  - Owner overpayment attempt rejected (422 — no float).
  - Overpayment rejected when a change denomination is out of
    stock (409); account and float state unchanged.

Out of scope (deferred to a later slice):

- PIN verification for cashier receipt-confirm and float-return
  flows.
- `vault_transactions` audit rows for cash-out / transfer / exchange
  / overpayment denomination movements.
- Reverb WebSocket balance / new-transaction broadcasts.

Verification passed in this slice:

```bash
php artisan test
vendor/bin/pint --test app/Http/Requests/CashInRequest.php \
  app/Services/TransactionService.php \
  app/Http/Controllers/Api/TransactionController.php \
  tests/Feature/CashInOverpaymentTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
```

Current PHPUnit result:

```text
92 tests, 17 passed, 75 skipped, 45 assertions
```

The 7 new `CashInOverpaymentTest` cases are DB-backed and remain
skipped until `pdo_sqlite` (or a MySQL test database) is enabled.

## Next Slice: PIN Verification

Goal: port Python `VaultService._verify_pin` bcrypt PIN check into the
float-lifecycle transitions that require it — employee activation and
cashier return confirmation.

Completed:

- Added `App\Http\Requests\SetPinRequest` (4–8 digit PIN).
- Added `POST /api/auth/pin` on `AuthController::setPin`
  (`ngwe.auth` + `throttle:5,1`) — stores `Hash::make($pin)` in
  `users.pin_hash`.
- Added `App\Services\PinVerifier::verify(User $user, ?string $pin)`
  that throws `InvalidArgumentException` for missing PIN, no PIN
  stored, or PIN mismatch. Mirrors Python `_verify_pin` semantics
  with Laravel's `Hash::check` (accepts bcrypt `$2b$` and `$2y$`
  variants).
- Extended `App\Services\CashFloatService` with the `PinVerifier`
  dependency:
  - `activate(User $employee, CashFloatAssignment $float, ?string
    $pin)` now requires the employee's PIN.
  - `confirmReturn(User $cashier, CashFloatAssignment $float,
    float|string $closingTotal, ?string $pin)` now requires the
    cashier's PIN.
- Added `App\Http\Requests\ActivateCashFloatRequest` (validates
  `pin: required, 4–8 digits`).
- Extended `App\Http\Requests\ConfirmFloatReturnRequest` with the
  same PIN rule.
- Updated `App\Http\Controllers\Api\CashFloatController::activate`
  and `confirmReturn` to pass the PIN into the service. `activate`
  now performs the float-ownership check in the controller (403)
  before the service call so that PIN failures map cleanly to 422.
- Updated existing tests that go through activate / confirmReturn to
  seed a PIN and send it (`CashFloatLifecycleTest`, `VaultLedgerTest`,
  `EmployeeCashOutFloatTest`, `EmployeeTransferExchangeFloatTest`,
  `CashInOverpaymentTest`).
- Added `PinVerificationTest` (7 cases):
  - Authenticated user can set PIN.
  - Non-numeric PIN rejected at request validation (422).
  - Too-short PIN rejected at request validation (422).
  - Activate with wrong PIN returns 422 "Incorrect PIN." and float
    stays `PENDING_RECEIPT`.
  - Activate with a PIN when no PIN is set returns 422 "No PIN set…".
  - Activate without a `pin` field returns 422 with a `pin`
    validation error.
  - Confirm-return with wrong PIN returns 422, float stays
    `PENDING_RECONCILIATION`; correct PIN closes the float.

Out of scope (deferred to a later slice):

- Reverb WebSocket balance / new-transaction broadcasts.
- `vault_transactions` audit rows for cash-out / transfer /
  exchange / overpayment denomination movements.
- Employee-verified denomination breakdown check at activation
  (Python `confirm_receipt` compares issued vs counted per denom).

Verification passed in this slice:

```bash
php artisan test
vendor/bin/pint --test app/Http/Requests/SetPinRequest.php \
  app/Http/Requests/ActivateCashFloatRequest.php \
  app/Http/Requests/ConfirmFloatReturnRequest.php \
  app/Services/PinVerifier.php \
  app/Services/CashFloatService.php \
  app/Http/Controllers/Api/AuthController.php \
  app/Http/Controllers/Api/CashFloatController.php \
  routes/api.php \
  tests/Feature/PinVerificationTest.php \
  tests/Feature/CashFloatLifecycleTest.php \
  tests/Feature/VaultLedgerTest.php \
  tests/Feature/EmployeeCashOutFloatTest.php \
  tests/Feature/EmployeeTransferExchangeFloatTest.php \
  tests/Feature/CashInOverpaymentTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
```

Current PHPUnit result:

```text
99 tests, 17 passed, 82 skipped, 45 assertions
```

The 7 new `PinVerificationTest` cases are DB-backed and remain
skipped until `pdo_sqlite` (or a MySQL test database) is enabled.

## Next Slice: Reverb WebSocket + Vault Transactions Audit (Planned)

- Add Reverb balance / new-transaction broadcasts on the money-movement
  and float-lifecycle endpoints.
- Add `vault_transactions` audit rows for cash-out / transfer /
  exchange / overpayment denomination movements.
- Port employee denomination re-verification at float activation
  (compare issued vs counted per denomination, matches Python
  `confirm_receipt`).

Verification passed in this slice:

```bash
php artisan test
vendor/bin/pint --test app/Repositories/TransactionRepository.php \
  app/Services/TransactionService.php \
  app/Http/Requests/CashInRequest.php \
  app/Http/Requests/CashOutRequest.php \
  app/Http/Requests/TransferRequest.php \
  app/Http/Requests/CancelCashInRequest.php \
  app/Http/Resources/TransactionResource.php \
  app/Http/Controllers/Api/TransactionController.php \
  routes/api.php \
  tests/Feature/TransactionEndpointsTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
php artisan route:list --path=api
```

Current PHPUnit result:

```text
44 tests, 17 passed, 27 skipped, 45 assertions
```

The 11 new `TransactionEndpointsTest` cases are DB-backed and remain
skipped until `pdo_sqlite` (or a MySQL test database) is enabled in
Laragon PHP. Pint on all files touched in this slice passes;
TypeScript, ESLint, and Vite build all pass.

Local PHP note: Laragon has `php_pdo_sqlite.dll` and `php_sqlite3.dll`
under `ext/` but both are commented out in `php.ini` around lines 938
and 949. Uncommenting those lines is a one-line environment change
that unblocks 27 currently-skipped DB tests, but was intentionally
left outside this slice.

## Slice A: Reverb WebSocket Broadcasts

Goal: port the Python `ConnectionManager.broadcast_to_roles` behavior
to Laravel Reverb / Echo without bundling the planned vault transaction
audit or denomination re-verification work.

Completed:

- Added Reverb private channel auth for the HMAC bearer API flow:
  `/broadcasting/auth` now uses `api` plus `ngwe.auth`.
- Added role-scoped private channels: `owner`, `cashier`, and
  `employee`.
- Kept the existing `user.{userId}` private channel for targeted
  employee messages, now also requiring an active user.
- Added broadcast events:
  - `BalanceUpdated` as `balance_update` to owner / cashier /
    employee channels.
  - `NewTransaction` as `new_transaction` to owner / cashier /
    employee channels.
  - `CashInPending` as `cash_in_pending` to the cashier channel.
  - `FloatStatusChanged` as `float_status_changed` to owner,
    cashier, and the affected employee's `user.{id}` channel.
  - `BroadcastPing` as `ping` to the owner channel.
- Added `App\Services\RealtimeBroadcastService` to build REST-shaped
  payloads with existing API resources and dispatch realtime events
  after database transactions have committed.
- Wired `balance_update` after cash-in create, cash-in confirm,
  cash-in cancel, cash-out, transfer, exchange, and owner
  balance-adjust.
- Wired `new_transaction` after cash-in, cash-out, transfer, and
  exchange creation.
- Wired `cash_in_pending` when a created cash-in is in
  `PENDING_CASHIER_CONFIRM`.
- Wired `float_status_changed` after float issue, activate,
  initiate-return, and confirm-return.
- Added owner-only `POST /api/broadcast/test`, returning 200 and
  dispatching a `ping` event.
- Added `resources/js/lib/echo.ts` with a reusable Echo/Reverb helper
  for bearer-token private channel auth and role / user subscriptions.
- Added `ReverbBroadcastTest` with `Event::fake()` assertions for
  transaction broadcasts, balance updates, float lifecycle status
  broadcasts, and the ping endpoint.

Out of scope:

- `vault_transactions` audit rows.
- Denomination re-verification at float activation.
- Vue/Inertia pages that consume the Echo helper.
- MySQL test database wiring.

Verification passed in this slice:

```bash
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe vendor\bin\pint --test app\Events\Concerns\UsesNgweLweBroadcastChannels.php app\Events\BalanceUpdated.php app\Events\NewTransaction.php app\Events\CashInPending.php app\Events\FloatStatusChanged.php app\Events\BroadcastPing.php app\Services\RealtimeBroadcastService.php app\Services\TransactionService.php app\Services\CashFloatService.php app\Http\Controllers\Api\RealtimeBroadcastController.php app\Http\Controllers\Api\AccountController.php bootstrap\app.php routes\channels.php routes\api.php tests\Feature\ReverbBroadcastTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
```

NPM scripts were run with `C:\laragon\bin\nodejs\node-v22.14.0-win-x64`
prepended to `PATH` because `node` is not globally available in this
PowerShell environment.

Current PHPUnit result:

```text
104 tests, 17 passed, 87 skipped, 45 assertions
```

The 5 new `ReverbBroadcastTest` cases are DB-backed and remain skipped
until `pdo_sqlite` (or a MySQL test database) is enabled.

## Slice B: Vault Transactions Audit

Goal: add the immutable `vault_transactions` audit rows that mirror
Python `VaultTransactionRepository`, keeping denomination
re-verification, frontend pages, and MySQL test DB wiring out of this
slice.

Completed:

- Added `App\Models\VaultTransaction` for the existing
  `vault_transactions` table.
- Added `App\Repositories\VaultTransactionRepository` with:
  - `recordBulk()` for one row per positive denomination quantity.
  - Validation for supported transaction types:
    `float_issue`, `float_receipt`, `cash_out`,
    `return_initiate`, `return_confirm`, `adjustment`.
  - `paginateLog()` with filters for `txn_type`, `float_id`,
    `date_from`, and `date_to`.
- Added `VaultTransactionResource` and `VaultLogRequest`.
- Added owner-only `GET /api/vault/log`, paginated and filterable.
- Wired `CashFloatService` audit rows:
  - `issue` writes `float_issue`.
  - `activate` writes `float_receipt`.
  - `initiateReturn` writes `return_initiate`.
  - `confirmReturn` writes `return_confirm` with
    `verified_by = cashier`.
- Wired `TransactionService` employee-float denomination draws:
  - `createCashOut` writes `cash_out`.
  - `createTransfer` writes `cash_out`.
  - `createExchange` writes `cash_out`.
  - Cash-in overpayment change writes `cash_out`.
- Added `VaultTransactionAuditTest` covering one row per denomination
  for float lifecycle operations, employee cash draw operations, and
  owner-only filtered log access.

Out of scope:

- Denomination re-verification at float activation.
- Frontend Vue/Inertia pages.
- MySQL test database wiring.

Verification passed in this slice:

```bash
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe vendor\bin\pint --test app\Models\VaultTransaction.php app\Repositories\VaultTransactionRepository.php app\Http\Resources\VaultTransactionResource.php app\Http\Requests\VaultLogRequest.php app\Http\Controllers\Api\VaultController.php app\Services\CashFloatService.php app\Services\TransactionService.php routes\api.php tests\Feature\VaultTransactionAuditTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan route:list --path=api
```

NPM scripts were run with `C:\laragon\bin\nodejs\node-v22.14.0-win-x64`
prepended to `PATH` because `node` is not globally available in this
PowerShell environment.

Current PHPUnit result:

```text
107 tests, 17 passed, 90 skipped, 45 assertions
```

The 3 new `VaultTransactionAuditTest` cases are DB-backed and remain
skipped until `pdo_sqlite` (or a MySQL test database) is enabled.

## Slice C: Denomination Re-verification At Float Activate

Goal: port Python `confirm_receipt` denomination re-count behavior so
an employee can only activate a float after the counted notes match the
cashier-issued notes.

Completed:

- Extended `ActivateCashFloatRequest` with required
  `verified_denominations` and the same supported-note validation
  style used by issue / return denomination requests.
- Updated `CashFloatController::activate` to pass the verified map
  into the service.
- Updated `CashFloatService::activate` so the order is:
  - ownership guard
  - employee PIN verification
  - issued-vs-counted denomination comparison
  - `PENDING_RECEIPT -> ACTIVE` transition and `float_receipt`
    audit rows
- Compared every supported MMK denomination, treating missing notes as
  zero, and rejected any mismatch with:
  `Denomination {denom} MMK — Issued: X, You counted: Y`.
- Updated existing activate call sites in feature tests to send/pass
  exact verified denomination maps.
- Added `FloatActivationDenominationVerificationTest` covering:
  - exact count activates the float
  - short-count rejects with 422 and leaves the float
    `PENDING_RECEIPT`
  - over-count rejects with 422 and leaves the float
    `PENDING_RECEIPT`

Out of scope:

- Vue/Inertia pages for the employee receipt-counting UI.
- MySQL test database wiring.

Verification passed in this slice:

```bash
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe vendor\bin\pint --test app\Http\Requests\ActivateCashFloatRequest.php app\Services\CashFloatService.php app\Http\Controllers\Api\CashFloatController.php tests\Feature\CashFloatLifecycleTest.php tests\Feature\PinVerificationTest.php tests\Feature\EmployeeCashOutFloatTest.php tests\Feature\EmployeeTransferExchangeFloatTest.php tests\Feature\CashInOverpaymentTest.php tests\Feature\VaultLedgerTest.php tests\Feature\VaultTransactionAuditTest.php tests\Feature\ReverbBroadcastTest.php tests\Feature\FloatActivationDenominationVerificationTest.php
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run build
```

NPM scripts were run with `C:\laragon\bin\nodejs\node-v22.14.0-win-x64`
prepended to `PATH` because `node` is not globally available in this
PowerShell environment.

Current PHPUnit result:

```text
110 tests, 17 passed, 93 skipped, 45 assertions
```

The 3 new `FloatActivationDenominationVerificationTest` cases are
DB-backed and remain skipped until `pdo_sqlite` (or a MySQL test
database) is enabled.

## Slice D: Vue/Inertia Frontend Pages

Goal: replace the migration-status shell with a role-aware operating
console for the completed Ngwe Lwe API workflows.

Completed:

- Replaced the `Welcome.vue` migration placeholder with an Inertia
  operations console at `/`.
- Added API session handling:
  - username/password login via `POST /api/auth/login`
  - token restore via `GET /api/auth/me`
  - logout and authenticated PIN update
  - local token persistence for the browser session
- Added role-aware views for:
  - Owner: company, service type, account, balance-adjust,
    exchange-rate, vault-log, and broadcast-ping workflows.
  - Cashier: pending cash-in confirmation/cancellation, float issue,
    and float return confirmation.
  - Employee: cash-in, cash-out, transfer, exchange, float activation
    with `verified_denominations`, and float return initiation.
- Added live data loading for companies, service types, accounts,
  recent transactions, cash floats, vault inventory, vault log
  (owner), and latest exchange rate.
- Wired the existing Echo helper into the UI for role/user private
  channel subscriptions and a realtime event feed.
- Added `resources/js/lib/api.ts` for typed JSON fetch handling and
  `resources/js/types/domain.ts` for frontend API contract types.
- Reworked `resources/css/app.css` into a dense, responsive
  operations-console layout with stable tables, forms, segmented
  controls, metrics, and status states.

Out of scope:

- Seeded demo credentials / user management screens.
- MySQL test database wiring.

Verification passed in this slice:

```bash
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test
npm.cmd run types:check
npm.cmd run lint:check
npm.cmd run format:check
npm.cmd run build
```

NPM scripts were run with `C:\laragon\bin\nodejs\node-v22.14.0-win-x64`
prepended to `PATH` because `node` is not globally available in this
PowerShell environment.

Current PHPUnit result:

```text
110 tests, 17 passed, 93 skipped, 45 assertions
```

The skipped tests are still DB-backed suites waiting for `pdo_sqlite`
or a MySQL test database.

## Next Slice: MySQL Test Database Verification

- Configure `ngwe_lwe_laravel` and `ngwe_lwe_laravel_test` for local
  Laravel/MySQL verification.
- Move the DB-backed feature suites off skipped SQLite-only execution.
- Run the migration and API suites against MySQL.
