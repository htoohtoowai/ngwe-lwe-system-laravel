# Ngwe Lwe Laravel Migration Report

Date: 2026-07-01

## Correction

The correct project is a Python to Laravel rewrite:

```text
Source: C:\laragon\www\ngwe-lwe-system
Target: C:\laragon\www\ngwe-lwe-system-laravel
```

The earlier Flight/Telemetry direction was wrong and should not be continued.

## Completed In This Slice

- Added Laravel migration for the Ngwe Lwe core database schema.
- Added Laravel user columns for `username`, `pin_hash`, `full_name`, `role`, `is_active`, and `auth_version`.
- Added Ngwe Lwe tables for companies, service types, accounts, transactions, commission tiers, exchange rates, daily summaries, activity logs, cash floats, denomination logs, vault transactions, denomination balances, transaction payment denominations, and daily reconciliation logs.
- Seeded MMK note denominations: `50`, `100`, `200`, `500`, `1000`, `5000`, `10000`, `20000`.
- Added `App\Support\Money` to port Python money behavior.
- Added tests for money normalization, MMK fee rounding, denomination totals, and schema expectations.
- Removed wrong Flight/Telemetry active code.
- Added Ngwe Lwe base models and repositories.
- Added HMAC bearer token auth foundation with username login, active-user checks, `auth_version`, and owner/cashier/employee role middleware.
- Added authenticated CRUD API modules for companies, service types, and accounts.
- Added owner-only setup mutations, authenticated read endpoints, active-only listing by default, soft-deactivate delete behavior, and account filters.
- Added JSON resources and Form Requests for setup API validation/output.
- Normalized account balance and commission-rate inputs through `App\Support\Money`.

## Verification

Passed:

```bash
php artisan test
vendor/bin/pint --test app/Support/Money.php database/migrations/2026_07_01_000001_create_ngwe_lwe_core_schema.php tests/Unit/MoneyTest.php tests/Feature/NgweLweSchemaTest.php
npm run types:check
npm run lint:check
npm run build
```

Result:

- PHPUnit: 21 tests, 11 passed, 10 skipped, 33 assertions.
- Pint: passed.
- TypeScript, ESLint, and Vite build: passed.

Skipped tests:

- `NgweLweSchemaTest`, `NgweLweAuthTest`, and `NgweLweSetupApiTest` use in-memory SQLite, but current Laragon PHP has `pdo_mysql` enabled and does not have `pdo_sqlite` enabled.

## Slice Update: Commission Tiers, Fees, And Balance Guards

Completed in this slice:

- Added `CommissionTierRepository::findForAmount` that mirrors Python
  `get_tier_for_amount`, including catch-all vs specific tier priority.
- Added `App\Services\TransactionFeeCalculator`:
  - Ports FIXED vs PERCENTAGE resolution for base fee, additional fee,
    and commission.
  - Rounds `customer_fee` (base + additional) with `Money::roundMmkFee`.
  - Uses deposit columns for cash-in and withdraw columns for cash-out.
- Added `AccountRepository::incrementBalance` and
  `AccountRepository::debitBalance` (throws `InsufficientBalanceException`)
  so cash-in and transfer flows cannot overdraw the source account.
- Added `App\Models\ActivityLog` bound to the existing `activity_logs`
  table.
- Added owner-only `POST /api/accounts/{account}/balance-adjust` that
  writes an `activity_logs` audit row with `old_balance`, `new_balance`,
  `amount`, and `remark`.
- Added `TransactionFeeCalculatorTest` (unit, no DB) and
  `CommissionTierAndBalanceTest` (feature, DB-backed).

## Slice Update: Transaction Endpoints (Cash In / Cash Out / Transfer)

Completed in this slice:

- Added `App\Repositories\TransactionRepository` (create, find,
  filter, recent, confirm/cancel pending cash-in).
- Added `App\Services\TransactionService` with `createCashIn`,
  `createCashOut`, `createTransfer`, `confirmPendingCashIn`, and
  `cancelPendingCashIn`. All flows use the calculator, balance guard,
  and write `activity_logs` audit rows.
- Added Form Requests (`CashInRequest`, `CashOutRequest`,
  `TransferRequest`, `CancelCashInRequest`) and `TransactionResource`.
- Added `TransactionController` with role-gated endpoints:
  - `GET /api/transactions`, `GET /api/transactions/recent`,
    `GET /api/transactions/{transaction}`.
  - `POST /api/transactions/cash-in`,
    `POST /api/transactions/cash-out`,
    `POST /api/transactions/transfer`.
  - `POST /api/transactions/{transaction}/confirm-cash-in`,
    `POST /api/transactions/{transaction}/cancel-cash-in`
    (cashier / owner).
  - `DELETE /api/transactions/{transaction}` — owner-only, always
    409 with the Python "hard delete disabled" message.
- Added `TransactionEndpointsTest` covering cash-in pending status,
  overdraw rejection, cash-out credit, transfer + overdraw, same
  account rejection, cashier create block, cashier confirm/cancel,
  confirm idempotency, filtered owner list, and hard-delete guard.

## Slice Update: Exchange Rates And Exchange Transactions

Completed in this slice:

- Added `App\Models\ExchangeRate` and
  `App\Repositories\ExchangeRateRepository`.
- Added `ExchangeRateController` with authenticated reads
  (`index`, `latest`, `show`) and owner-only writes.
- Added `App\Services\TransactionService::createExchange` that
  applies `sell_rate / base_amount` for MMK output, `buy_rate /
  base_amount` for THB output, credits the source account,
  writes an `activity_logs` audit row, and uses the fee calculator.
- Added `POST /api/transactions/exchange` endpoint (cashier-blocked,
  422 on invalid currency or missing rate).
- Added `ExchangeRateAndTransactionTest` covering CRUD, `/latest`,
  MMK vs THB rate direction, `base_amount` divisor, missing rate,
  and cashier rejection.

## Slice Update: Cash Float Lifecycle

Completed in this slice:

- Added `App\Models\CashFloatDenomination` and a `denominations`
  HasMany on `CashFloatAssignment`.
- Added `App\Repositories\CashFloatRepository` (issue, activate,
  initiate-return, confirm-return, deduct balance, list, find).
- Added `App\Services\CashFloatService` that wraps the repository
  with role checks and writes `activity_logs` for each lifecycle
  transition.
- Added `App\Exceptions\InsufficientFloatException`.
- Added Form Requests, Resource, and `CashFloatController` with role
  gating.
- Registered `Route::model('float', CashFloatAssignment::class)` so
  the `{float}` URL parameter resolves cleanly.
- Added lifecycle endpoints:
  - `GET /api/cash-floats`, `GET /api/cash-floats/{float}` — reads,
    employees scoped to their own floats.
  - Cashier / owner: `POST /api/cash-floats` (issue),
    `POST /api/cash-floats/{float}/confirm-return`.
  - Employee: `POST /api/cash-floats/{float}/activate`,
    `POST /api/cash-floats/{float}/initiate-return`.
- Added `CashFloatLifecycleTest` (10 cases) covering issue,
  activation, cross-employee protection, initiate/confirm return,
  wrong-status rejections, and employee list scoping.

## Slice Update: Vault Denomination Ledger

Completed in this slice:

- Added `App\Models\CashDenominationLog` and
  `App\Models\VaultDenominationBalance`.
- Added `App\Exceptions\InsufficientVaultDenominationException`.
- Added `App\Repositories\CashDenominationRepository` with
  `recordBulk`, `getVaultBalance`, `getPendingReserved`,
  `getAvailableBalance`, and `recentLogs`. `recordBulk` writes one
  log row per denomination and atomically applies the delta to
  `vault_denomination_balances`; `vault_out` rejects when it would
  produce a negative balance.
- Wired `CashFloatService::issue` to write a `vault_out` ledger
  entry (main vault debited at issuance) and
  `CashFloatService::confirmReturn` to write a `float_returned`
  entry that credits the main vault.
- Added `App\Http\Controllers\Api\VaultController`:
  - `GET /api/vault/balance` — vault / pending / available maps and
    totals.
  - `GET /api/vault/inventory` — main vault + open employee floats
    with denomination breakdowns and a grand physical total.
- Updated `CashFloatLifecycleTest` to seed the vault before the
  issue-API test (issue now guards on vault stock).
- Added `VaultLedgerTest` (8 cases) covering net balance, negative
  guard, issue debit, insufficient-stock rejection at issue,
  confirm-return credit, and the two vault endpoints.

## Slice Update: Employee Denomination-Aware Cash Out

Completed in this slice:

- Extended `App\Repositories\CashFloatRepository` with
  `getDenominationBalance` and `deductDenominations` (atomic per-row
  UPDATE guarded by `WHERE quantity >= ?`).
- Added `App\Exceptions\InsufficientFloatDenominationException`.
- Added `App\Services\EmployeeFloatValidator::validateCashOut` —
  pure validation of active float, denomination map, per-note stock,
  denom-total-vs-amount, and float `current_balance`.
- Updated `App\Services\TransactionService::createCashOut`: when the
  creator is an employee, the validator runs before writes and
  `deductDenominations` + `deductBalance` fire inside the DB
  transaction. Owner / cashier flow unchanged.
- Extended `App\Http\Requests\CashOutRequest` to accept an optional
  `denominations` map with supported-note validation.
- `TransactionController::cashOut` now translates float shortfalls
  to HTTP 409.
- Added `EmployeeCashOutFloatTest` (6 cases) covering deduction,
  missing denominations, missing float, total mismatch, stock
  shortfall, and unchanged owner behavior.

## Slice Update: Employee Denomination-Aware Transfer + Exchange

Completed in this slice:

- Renamed `EmployeeFloatValidator::validateCashOut` to
  `validateFloatOperation` with an operation-label argument (logic
  unchanged; same rule set applies to cash-out, transfer, exchange).
- `TransactionService::createTransfer` and `createExchange` now run
  the validator when the creator is an employee and, inside the DB
  transaction, deduct denominations and float balance after the
  ledger movement.
- Added optional `denominations` field to `TransferRequest` and
  `ExchangeRequest` with supported-note validation.
- `TransactionController::transfer` and `exchange` translate
  `InsufficientFloat*` exceptions to HTTP 409.
- Added `EmployeeTransferExchangeFloatTest` (7 cases): employee
  transfer/exchange deducts float, missing denominations 422,
  insufficient stock 409 (state unchanged), denom-total mismatch
  422, owner transfer still works without denominations.

## Slice Update: Cash-In Overpayment Change

Completed in this slice:

- Extended `CashInRequest` with optional `amount_received` and
  `change_denominations` (supported-note validation).
- Updated `TransactionService::createCashIn`:
  - Rejects `amount_received < amount` and
    `change_denominations` without overpayment.
  - When `change_due > 0`, requires an employee, validates float
    via `validateFloatOperation(..., 'cash-in overpayment change')`,
    and deducts denominations + float balance inside the DB
    transaction. Persists `change_given` and `change_denominations`
    on the transaction and writes a
    `cash_in_overpayment_change_given` activity log entry.
- `TransactionController::cashIn` translates float shortfalls to
  HTTP 409.
- Added `CashInOverpaymentTest` (7 cases): no-overpayment path,
  overpayment deducts float, change denom total ≠ change_due,
  amount_received < amount, change denoms without overpayment, owner
  cannot overpay, float stock shortfall.

## Slice Update: PIN Verification

Completed in this slice:

- Added `App\Http\Requests\SetPinRequest` and
  `POST /api/auth/pin` for authenticated users to set/change a 4–8
  digit PIN (bcrypt-hashed).
- Added `App\Services\PinVerifier` that mirrors Python
  `_verify_pin` and throws `InvalidArgumentException` on missing PIN,
  no PIN set, or mismatch.
- `CashFloatService::activate` now requires the employee's PIN and
  `confirmReturn` requires the cashier's PIN.
- Added `App\Http\Requests\ActivateCashFloatRequest` and extended
  `ConfirmFloatReturnRequest` with a PIN rule.
- `CashFloatController::activate` performs the float-ownership check
  in the controller (403) before service invocation so PIN errors
  cleanly map to 422.
- Updated the five existing DB-backed tests that go through activate
  / confirmReturn to seed a PIN and send it.
- Added `PinVerificationTest` (7 cases): set PIN, non-numeric PIN
  rejected, short PIN rejected, activate wrong PIN, activate with
  no PIN set, activate missing PIN field, confirm-return wrong PIN
  then correct PIN.

## Slice Update: Reverb WebSocket Broadcasts

Completed in this slice:

- Configured `/broadcasting/auth` for the existing HMAC bearer API
  middleware and added private role channels for `owner`, `cashier`,
  and `employee`.
- Added broadcast events for `balance_update`, `new_transaction`,
  `cash_in_pending`, `float_status_changed`, and owner-only `ping`.
- Added `RealtimeBroadcastService` so realtime payloads use existing
  API Resources and dispatch after database transactions finish.
- Wired balance updates after cash-in create / confirm / cancel,
  cash-out, transfer, exchange, and balance-adjust.
- Wired transaction and pending-cash-in broadcasts after transaction
  creation.
- Wired float status broadcasts after issue, activate,
  initiate-return, and confirm-return; targeted employee delivery uses
  the existing `user.{id}` channel.
- Added owner-only `POST /api/broadcast/test`.
- Added `resources/js/lib/echo.ts` as the reusable Echo/Reverb helper
  for Slice D UI work.
- Added `ReverbBroadcastTest` with `Event::fake()` coverage for the
  broadcast dispatch points.

## Slice Update: Vault Transactions Audit

Completed in this slice:

- Added `VaultTransaction` model, `VaultTransactionRepository`,
  `VaultTransactionResource`, and `VaultLogRequest`.
- Wired one `vault_transactions` row per denomination quantity for:
  float issue, float receipt, return initiation, return confirmation,
  employee cash-out, employee transfer, employee exchange, and cash-in
  overpayment change.
- `return_confirm` rows now set `verified_by` to the cashier; all
  other audit writes leave it null.
- Added owner-only `GET /api/vault/log`, paginated and filterable by
  `txn_type`, `float_id`, `date_from`, and `date_to`.
- Added `VaultTransactionAuditTest` for lifecycle audit rows,
  employee cash draw audit rows, and owner-only log access.

## Slice Update: Denomination Re-verification At Float Activate

Completed in this slice:

- `POST /api/cash-floats/{float}/activate` now requires
  `verified_denominations` alongside the employee PIN.
- `ActivateCashFloatRequest` validates the verified denomination map
  and rejects unsupported MMK note keys.
- `CashFloatService::activate` now verifies PIN first, then compares
  issued-vs-counted quantities for every supported denomination before
  changing the float from `PENDING_RECEIPT` to `ACTIVE`.
- Short-count and over-count attempts return HTTP 422 with the
  Python-style mismatch message:
  `Denomination {denom} MMK — Issued: X, You counted: Y`.
- Rejections leave the float in `PENDING_RECEIPT`; successful matches
  still write the existing `float_receipt` vault transaction audit
  rows.
- Added `FloatActivationDenominationVerificationTest` (3 cases) for
  exact match, short-count rejection, and over-count rejection.

## Slice Update: Vue/Inertia Frontend Pages

Completed in this slice:

- Replaced the placeholder migration landing screen with a role-aware
  Inertia operations console at `/`.
- Added browser API session handling for login, token restore, logout,
  and authenticated PIN update.
- Added owner workflows for setup data, balance adjustment, exchange
  rates, vault log visibility, and broadcast ping.
- Added cashier workflows for pending cash-in approval/cancellation,
  float issue, and return confirmation.
- Added employee workflows for cash-in, cash-out, transfer, exchange,
  float activation with verified denominations, and return initiation.
- Added live summary panels, recent transaction and cash-float tables,
  vault inventory, and latest exchange-rate display.
- Wired `resources/js/lib/echo.ts` into the console for role/user
  private channel subscriptions and realtime event capture.
- Added typed frontend API helpers and domain response contracts.
- Reworked the CSS into a dense responsive operations layout.

## Slice Update: MySQL Test Database Verification

Completed in this slice:

- Created local MySQL databases `ngwe_lwe_laravel` and
  `ngwe_lwe_laravel_test`.
- Updated `phpunit.xml` so PHPUnit uses MySQL
  `ngwe_lwe_laravel_test` by default.
- Added a shared `skipIfDatabaseUnavailable()` guard in
  `Tests\TestCase` and replaced the old SQLite-only skip checks across
  DB-backed feature suites.
- Ran the full migration/API/feature suite against MySQL:
  `110 tests, 110 passed, 455 assertions`.
- Fixed MySQL-exposed test/API gaps:
  - `TransactionResource` now exposes `change_given` and stable
    `change_denominations` maps.
  - Float lifecycle audit coverage now issues via `CashFloatService`.
  - Broadcast lifecycle activation/return counts now match the issued
    denominations.
  - Cash-in overpayment coverage now expects the correct remaining
    float balance after change is given.

## Slice Update: Demo Seed Data

Completed in this slice:

- Replaced the placeholder Laravel `DatabaseSeeder` user with
  deterministic Ngwe Lwe demo users:
  - `owner` / `password123` / PIN `1111`
  - `cashier` / `password123` / PIN `2222`
  - `employee` / `password123` / PIN `3333`
- Seeded a demo Wave Money setup with Cash In, Cash Out, Transfer, and
  Exchange service types.
- Seeded six demo accounts, including a fee account, without resetting
  existing account balances on repeated seeder runs.
- Seeded catch-all commission tiers and a THB/MMK exchange rate.
- Seeded an opening main-vault denomination balance only when the vault
  is empty, so repeated `db:seed` runs do not double-credit cash.
- Added `DatabaseSeederTest` coverage for demo login, seeded operating
  data, and idempotent repeated seeding.
- Updated Docker and README instructions with the seeding command and
  demo credentials.

Verification passed:

```bash
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe -l database\seeders\DatabaseSeeder.php
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe -l tests\Feature\DatabaseSeederTest.php
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test --filter=DatabaseSeederTest
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe vendor\bin\pint --test database\seeders\DatabaseSeeder.php tests\Feature\DatabaseSeederTest.php
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test
```

Current PHPUnit result:

```text
114 tests, 114 passed, 525 assertions
```

## Slice Update: Manual Browser Workflow QA

Completed in this slice:

- Fixed the local runtime environment for browser QA:
  - `.env` now points the local app to MySQL `ngwe_lwe_laravel`
    instead of the default SQLite connection.
  - Local Reverb was moved to port `8081` because Laragon/nginx was
    already listening on `8080`.
  - Removed stale local Octane and Telemetry env values from `.env`.
- Started local services for the walkthrough:
  - Laravel app: `http://127.0.0.1:8001`
  - Vite dev server: `http://127.0.0.1:5173`
  - Reverb: `127.0.0.1:8081`
- Seeded the MySQL development database with the demo users and setup
  data.
- Verified owner login and realtime ping in the operations console.
- Verified cashier workflow:
  - Login as `cashier`.
  - Issue float `#1` to employee `#3` for `40,000` MMK.
  - Main vault changed from `4,135,000` to `4,095,000`.
  - Employee cash changed from `0` to `40,000`.
  - `float_status_changed` arrived without manual refresh.
- Verified employee workflow:
  - Login as `employee`.
  - Activate float `#1` with PIN `3333` and verified denominations
    `{"10000": 3, "5000": 2}`.
  - Float moved to `ACTIVE`, current balance `40,000`.
  - Create Cash In transaction `#1` for `1,000` MMK.
  - `new_transaction` and `balance_update` arrived without manual
    refresh.
- Verified cashier confirmation:
  - Login as `cashier`.
  - Pending Cash In `#1` appeared in the cashier panel.
  - Confirm changed transaction status to `COMPLETED`.
  - Pending Cash In count returned to `0`.

Notes:

- The in-app browser uses one shared localStorage profile, so separate
  tabs cannot stay logged in as different users at the same time. Real
  cashier/employee computers or separate browser profiles will have
  separate tokens.

Verification passed:

```bash
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan migrate --seed --force
Invoke-WebRequest -UseBasicParsing -Method Post -Uri http://127.0.0.1:8001/api/auth/login -ContentType 'application/json' -Body '{"username":"owner","password":"password123"}'
Browser QA through the Vue/Inertia operations console
```

## Slice Update: Docker Runtime Hardening

Completed in this slice:

- Extended `docker/ensure-env.sh` so Docker app and Reverb containers
  fail fast when required runtime values are missing or unsafe.
- Required environment values now include:
  - `APP_KEY`
  - MySQL connection identity: `DB_CONNECTION`, `DB_HOST`,
    `DB_DATABASE`, `DB_USERNAME`
  - `NGWE_LWE_AUTH_SECRET`
  - `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`
  - `VITE_REVERB_APP_KEY`
- Added production guardrails:
  - Reject placeholder `APP_KEY` values.
  - Reject `APP_DEBUG=true` when `APP_ENV=production`.
  - Require `NGWE_LWE_AUTH_SECRET` length of at least 32 characters.
  - Require `VITE_REVERB_APP_KEY` to match `REVERB_APP_KEY`, preventing
    browser realtime auth drift.
- Added `DockerEnvironmentTest` to keep the `.env.example` and startup
  guard aligned with the expected deployment variables.
- Updated Docker docs to explain the startup guard and the Reverb key
  match requirement.

Verification passed:

```bash
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe -l tests\Unit\DockerEnvironmentTest.php
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test --filter=DockerEnvironmentTest
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe vendor\bin\pint --test tests\Unit\DockerEnvironmentTest.php
docker compose config --quiet
git diff --check
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test
```

Current PHPUnit result:

```text
114 tests, 114 passed, 525 assertions
```

## Slice Update: Owner Staff User Management

Completed in this slice:

- Added owner-only staff user management API endpoints:
  - `GET /api/users`
  - `POST /api/users`
  - `GET /api/users/{user}`
  - `PATCH /api/users/{user}`
  - `DELETE /api/users/{user}`
- Added `UserRequest` validation and `UserResource` output so password
  and PIN hashes never leave the API.
- Extended `UserRepository` with create, update, active/inactive
  listing, and deactivate behavior.
- Token safety is preserved:
  - Role, username, password, and active-status changes increment
    `auth_version`.
  - Deactivating a user revokes existing tokens.
  - Owners cannot deactivate their own active session.
- Added a Staff Users panel to the Vue/Inertia operations console for
  owner create/edit/deactivate workflows.
- Added `UserManagementTest` coverage for owner CRUD, non-owner
  rejection, self-deactivate rejection, and token revocation.

Verification passed:

```bash
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe -l app\Repositories\UserRepository.php
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe -l app\Http\Controllers\Api\UserController.php
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe -l app\Http\Requests\UserRequest.php
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe vendor\bin\pint --test app\Repositories\UserRepository.php app\Http\Controllers\Api\UserController.php app\Http\Requests\UserRequest.php app\Http\Resources\UserResource.php routes\api.php tests\Feature\UserManagementTest.php
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\prettier\bin\prettier.cjs --check resources/
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\eslint\bin\eslint.js .
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\vue-tsc\bin\vue-tsc.js --noEmit
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\vite\bin\vite.js build
docker compose config --quiet
git diff --check
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test --filter=UserManagementTest
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test
```

Current PHPUnit result:

```text
118 tests, 118 passed, 557 assertions
```

## Slice Update: Daily Reports And Reconciliation

Completed in this slice:

- Added report/reconciliation models:
  - `App\Models\DailySummary`
  - `App\Models\DailyReconciliationLog`
- Added `App\Services\DailyReportService` to calculate daily completed
  transaction totals, fees, profit, pending Cash In count, vault cash,
  employee float cash, active account digital balances, and grand total.
- Added owner-only report endpoints:
  - `GET /api/reports/daily-summary`
  - `POST /api/reports/daily-reconciliation`
  - `GET /api/reports/daily-reconciliations`
- Closing a day upserts `daily_summary` and writes a
  `daily_reconciliation_logs` snapshot with account, employee-float, and
  vault denomination details.
- Added a Daily Report panel to the Vue/Inertia operations console for
  owner date refresh, Close Day, and recent reconciliation log review.
- Added `DailyReportTest` coverage for completed-only totals, pending
  Cash In counting, snapshot persistence, log listing, and non-owner
  rejection.

Verification passed:

```bash
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test --filter=DailyReportTest
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe vendor\bin\pint --test app\Models\DailySummary.php app\Models\DailyReconciliationLog.php app\Http\Requests\DailyReportRequest.php app\Http\Resources\DailyReconciliationResource.php app\Services\DailyReportService.php app\Http\Controllers\Api\ReportController.php routes\api.php tests\Feature\DailyReportTest.php
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\prettier\bin\prettier.cjs --check resources/
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\eslint\bin\eslint.js .
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\vue-tsc\bin\vue-tsc.js --noEmit
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\vite\bin\vite.js build
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe artisan test
```

Current PHPUnit result:

```text
121 tests, 121 passed, 589 assertions
```

## Slice Update: Dedicated Login Page

Completed in this slice:

- Added a standalone `/login` Inertia page for the Ngwe Lwe sign-in
  flow.
- Moved token storage helpers into `resources/js/lib/auth-token.ts`.
- Removed the login form from the operations console sidebar.
- The console now redirects unauthenticated users to `/login`; logout
  also returns to `/login`.
- Styled the login page as a focused enterprise sign-in surface.

Verification passed:

```bash
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\prettier\bin\prettier.cjs --check resources/
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\eslint\bin\eslint.js .
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\vue-tsc\bin\vue-tsc.js --noEmit
C:\laragon\bin\nodejs\node-v22.14.0-win-x64\node.exe node_modules\vite\bin\vite.js build
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe -l routes\web.php
C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe vendor\bin\pint --test routes\web.php
Browser QA: `/login` renders standalone form; `/` redirects to `/login` without a token.
```

## Remaining Work

1. Do a final Docker image build/run on the target machine before production use.
