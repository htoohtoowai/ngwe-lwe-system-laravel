# Function, Column, Form Field, and Flow Parity Audit

Audit date: 2026-07-26

Compared projects:

- Original Python app: `C:\laragon\www\ngwe-lwe-system`
- Laravel rewrite: `C:\laragon\www\ngwe-lwe-system-laravel`

Important scope note: the original Python calculation logic is not treated as the source of truth. This audit checks whether functions, request fields, database columns, UI form fields, and user flows exist or are missing/renamed in the Laravel rewrite.

## Executive Summary

The Laravel rewrite covers most core CRUD and money-transfer surfaces from the Python app:

- auth, user management, accounts, companies, service types, commission tiers
- exchange rates
- cash-in, cash-out, transfer, exchange transaction entry
- cashier vault, cash floats, float issue/receive/return
- pending cash-in confirm/cancel
- dashboard, reports, reconciliation, activity logs
- company logo upload/download

However, several parity gaps and implementation risks remain. The most important are:

1. `TransactionService::createExchange()` uses `$feePayment` without assigning it.
2. Duplicate/unsafe transaction migrations can add already-existing columns.
3. Original cashier transaction `approve` and `payment` endpoints are missing.
4. Some original compatibility endpoints are renamed but not aliased.
5. `daily_summary` / `daily_reconciliation_logs` column names changed from deposit/withdraw to cash_in/cash_out.
6. Daily PDF is only a 501 placeholder.
7. Desktop-only utilities such as local backup/server manager are not ported.

## Fix Status

Implemented after this audit:

- Fixed `TransactionService::createExchange()` missing `$feePayment`.
- Guarded duplicate transaction-column migrations with `Schema::hasColumn()`.
- Added cash-in request aliases for `received_breakdown` and `change_breakdown`.
- Added compatibility endpoints for `GET /api/users/employees`, `GET /api/cashier/floats/my-pending`, root `GET /health`, cashier `approve`, and cashier `payment`.
- Replaced the daily report PDF placeholder with a generated PDF response.

Still intentionally not ported:

- Raw Python `/ws-ticket` and `/ws` websocket flow; Laravel uses Reverb/broadcast events.
- Desktop-only startup/server-manager dialogs and SQLite backup utility.

## High Priority Findings

### P0: Exchange transaction can fail at runtime

Laravel file: `app/Services/TransactionService.php`

`createExchange()` references `$feePayment` inside the DB transaction, but unlike cash-in, cash-out, and transfer, it does not assign:

```php
$feePayment = $this->resolveFeePayment($data, $account, $fees['customer_fee']);
```

Impact:

- `/api/transactions/exchange`
- `/transactions/exchange`
- teller exchange form

Expected action: define `$feePayment` before the transaction closure and add/restore focused exchange tests.

### P0: Duplicate migration risk for existing transaction columns

Core migration `2026_07_01_000001_create_ngwe_lwe_core_schema.php` already creates:

- `transactions.fee_payment_method`

Later migration `2026_07_14_000006_add_fee_payment_method_to_transactions.php` adds the same column again without `Schema::hasColumn()`.

Observed test failure earlier:

- `Duplicate column name 'fee_payment_method'`

Impact:

- fresh/refresh migration test runs can fail
- deployment migration order can break

Expected action: make additive migrations idempotent or remove duplicate column creation from one side.

## API Endpoint Parity

### Covered

Original Python endpoints covered by Laravel equivalents:

- `POST /auth/login` -> `POST /api/auth/login`
- `POST /auth/logout` -> `POST /api/auth/logout`
- `/accounts` CRUD and balance adjust -> `/api/accounts`
- `/companies` CRUD, logo upload/download, service types -> `/api/companies`
- `/service-types` update/delete plus list/show/create -> `/api/service-types`
- `/commission-tiers` CRUD and lookup -> `/api/commission-tiers`
- `/exchange-rates` CRUD and latest -> `/api/exchange-rates`
- `/transactions/cash_in` -> `/api/transactions/cash-in`
- `/transactions/cash_out` -> `/api/transactions/cash-out`
- `/transactions/transfer` -> `/api/transactions/transfer`
- `/transactions/exchange` -> `/api/transactions/exchange`
- `/transactions/recent`, `/transactions/by-date`, list -> `/api/transactions/recent`, `/api/transactions/by-date`, `/api/transactions`
- `/cashier/vault`, `/cashier/vault/entry`, `/cashier/vault/logs`, `/cashier/vault/inventory`
- `/cashier/floats`, issue/receive/initiate-return/confirm-return
- `/cashier/pending-cash-ins`, confirm/cancel cash-in
- `/dashboard/summary`, `/dashboard/accounts`
- `/reports/daily`
- `/reconciliation/current`, close-day, history
- `/activity-logs`

### Missing or renamed

Original endpoint | Laravel status | Risk
---|---|---
`GET /users/employees` | Equivalent exists as `GET /api/cashier/employees`; no exact alias | old clients fail
`GET /cashier/floats/my-pending` | no exact endpoint; can infer via `GET /api/cash-floats` for teller | old clients fail
`POST /cashier/transactions/{id}/approve` | missing | cashier approval workflow compatibility gap
`POST /cashier/transactions/{id}/payment` | missing | fee/cash payment denomination workflow gap
`POST /ws-ticket` + websocket `/ws` | replaced by Reverb/broadcasting; no exact raw ws-ticket compatibility | old desktop client realtime fails
`GET /health` | exists as `/api/health`, not root `/health` | old health checks fail

## Request Field Parity

### Cash In

Original Python request fields:

- `account_id`
- `amount`
- `amount_received`
- `customer_name`
- `customer_phone`
- `screenshot_path`
- `customer_fee`
- `additional_fee_amount`
- `fee_account_id`
- `note`
- `received_breakdown`
- `change_breakdown`

Laravel request fields:

- `account_id`
- `amount`
- `customer_name`
- `customer_phone`
- `customer_fee`
- `additional_fee_amount`
- `fee_payment_method`
- `fee_account_id`
- `screenshot_path`
- `note`
- `amount_received`
- `received_denominations`
- `handoff_denominations`
- `change_denominations`

Gaps/renames:

- `received_breakdown` renamed to `received_denominations`
- `change_breakdown` renamed to `change_denominations`
- Laravel adds `handoff_denominations`
- Laravel adds `fee_payment_method`

Expected action: add backward-compatible aliases if any old client still posts `received_breakdown` / `change_breakdown`.

### Cash Out / Transfer / Exchange

Original and Laravel largely match:

- account fields
- amount
- screenshot path
- customer/additional fees
- fee account
- note
- denominations

Laravel adds `fee_payment_method`, which is useful but should be present in every transaction form consistently.

## Database Column Parity

### Mostly matched tables

- `users`
- `companies`
- `service_types`
- `accounts`
- `transactions`
- `commission_tiers`
- `exchange_rates`
- `activity_logs`
- `cash_float_assignments`
- `cash_float_denominations`
- `cash_denomination_logs`
- `vault_transactions`
- `note_denominations`
- `vault_denomination_balances`
- `transaction_payment_denominations`

### Naming differences

Original Python uses role names:

- `owner`
- `employee`
- `cashier`

Laravel uses:

- `admin`
- `teller`
- `cashier`

This is acceptable if all UI, routes, seeders, tests, and migration conversion paths agree.

Original Python report columns:

- `daily_summary.total_deposit`
- `daily_summary.total_withdraw`
- `daily_reconciliation_logs.total_deposit`
- `daily_reconciliation_logs.total_withdraw`

Laravel report columns:

- `daily_summary.total_cash_in`
- `daily_summary.total_cash_out`
- `daily_reconciliation_logs.total_cash_in`
- `daily_reconciliation_logs.total_cash_out`

This is semantically cleaner, but any compatibility layer or imported report data needs explicit mapping.

### Added Laravel transaction columns

Laravel adds useful transaction fields that extend the original:

- `fee_payment_method`
- `received_denominations`
- `handoff_denominations`

These support the newer cashier/teller handoff flow.

## Form Field / UI Flow Parity

### Original Python UI forms observed

Original desktop UI contains forms/dialogs for:

- login
- owner dashboard
- transaction entry
- cash-in, cash-out, transfer, exchange
- history filters
- account settings
- company settings
- service type settings
- commission tiers
- exchange rates
- password/PIN
- cashier vault entry
- issue float
- receive float
- return float
- pending cash-in confirmation
- transaction payment
- daily closing/reconciliation
- activity log filters

### Laravel UI forms observed

Laravel Vue/Inertia UI contains:

- login
- dashboard
- cashier operations
- cashier profile
- teller counter
- teller float
- teller cash-in, cash-out, transfer, exchange
- admin/bank transaction pages
- fee payment selector
- denomination drawers
- PIN dialog
- dashboard pending cash-in review

### UI gaps

Original UI flow | Laravel status
---|---
Cashier "Fee Payment" dialog using `TransactionPaymentRequest` | no equivalent route/form found
Generic cashier transaction approve dialog with gives/receives | no exact equivalent route/form found
Daily report PDF export | button exists but server returns 501
Desktop server config/startup/join-host dialogs | not ported; probably intentionally desktop-only
SQLite backup management | not ported

## Flow Parity

### Cash In

Laravel flow is more explicit than original:

1. Teller/admin creates cash-in.
2. Account is debited.
3. Transaction status becomes `PENDING_CASHIER_CONFIRM`.
4. Teller cash received/change/handoff denominations are tracked.
5. Cashier confirms/cancels.
6. Main vault is updated on confirmation.

This appears to cover and improve the original flow, but aliases for old request names may be needed.

### Cash Out

Covered:

- account credit
- denomination requirement
- teller float deduction
- admin main-vault deduction
- vault transaction audit

### Transfer

Covered:

- source debit
- target credit
- fee handling
- teller denomination/float deduction

### Exchange

Covered in routes/forms/tests, but currently risky due to the missing `$feePayment` assignment.

### Cash Float

Covered:

- issue
- receive/activate
- denomination verification
- initiate return
- confirm return
- active/pending list
- denomination balance

Compatibility gap:

- exact `/cashier/floats/my-pending` endpoint missing.

### Cashier Payment / Approval

Partially covered:

- pending cash-in confirmation/cancel is covered.

Missing:

- generic `/cashier/transactions/{id}/approve`
- `/cashier/transactions/{id}/payment`

The old code has separate dialog/request models for these, so confirm whether this workflow is obsolete or still needed.

## Recommended Fix Order

1. Fix `TransactionService::createExchange()` missing `$feePayment`.
2. Make duplicate migrations idempotent, especially `fee_payment_method`, `received_denominations`, and `handoff_denominations`.
3. Add compatibility route aliases:
   - `GET /api/users/employees`
   - `GET /api/cashier/floats/my-pending`
   - optional root `GET /health`
4. Decide whether old cashier `approve` and `payment` workflows are still required.
5. If required, port:
   - `POST /api/cashier/transactions/{transaction}/approve`
   - `POST /api/cashier/transactions/{transaction}/payment`
   - matching Vue cashier forms/dialogs
6. Implement `/reports/daily/pdf` or remove/disable the UI button.
7. Add request alias handling for old `received_breakdown` and `change_breakdown` if old clients remain in use.
8. Add a migration/data import mapping for report columns:
   - `total_deposit` -> `total_cash_in`
   - `total_withdraw` -> `total_cash_out`

## Verification Notes

Frontend checks run earlier:

- `npm run lint:check` passed
- `npm run types:check` passed

Backend tests could not pass in the current local MySQL test database because migration refresh hit schema collisions, including duplicate `fee_payment_method`.

This audit should be followed by fixing the migration collision first, then rerunning:

```powershell
& 'C:\laragon\bin\php\php-8.4.1-Win32-vs17-x64\php.exe' artisan test
```
