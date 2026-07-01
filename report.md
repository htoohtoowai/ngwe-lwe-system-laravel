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

## Remaining Work

1. Configure MySQL databases: `ngwe_lwe_laravel` and `ngwe_lwe_laravel_test`.
2. Replace skipped SQLite schema/auth/setup checks with MySQL migration verification.
3. Add `vault_transactions` audit rows for denomination movements.
4. Port employee denomination re-verification at float activation.
5. Build the Vue/Inertia frontend pages and wire Echo subscriptions.
