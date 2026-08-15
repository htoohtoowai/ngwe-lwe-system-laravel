# Final Requirements: Companies, Accounts, Fees and Transactions

This project starts from a new database. Legacy Service Type schema, legacy fee aliases and data backfills are not supported.

## Technology

- Laravel
- Vue 3
- Inertia.js
- Tailwind CSS
- MySQL

## Shared calculation type

All configurable customer fees, additional fees, transfer fees and agent commissions use the same application enum:

```text
FIXED
PERCENTAGE
```

Configured values support four decimal places (`0.0001`). Percentage values are human percentages:

```text
raw = amount * (configured_value / 100)
```

Customer fee uses the application's MMK rounding/minimum rule after calculation.

## Account features

Features are a PHP enum, not a database master table:

```text
cash_in
cash_out
send_money
receive_money
transfer
exchange
```

Features control whether an account can be used for an operation. They do **not** control agent commission.

## companies

```text
id
name
logo_path
category: Pay | Bank | Both
is_active
timestamps
```

## accounts

Each account is exactly one account type: `PAY` or `BANK`. There is no `BOTH` account type.

```text
id
company_id
account_name
account_type: PAY | BANK
account_identifier
balance
is_active
is_fee_account
is_agent
timestamps
```

`account_identifier` is generic:

- PAY account: phone number
- BANK account: bank account number

The same identifier may exist under different providers, so uniqueness is:

```text
unique(company_id, account_identifier)
```

Example: `09256149967` may exist under KBZPay, Wave Money, AYA Pay and CB Pay.

Agent rules:

```text
PAY + is_agent=true  -> eligible for agent commission
PAY + is_agent=false -> no agent commission
BANK                 -> never an agent account / no agent commission
```

For a `Pay` company, account type must be `PAY`; for a `Bank` company it must be `BANK`; a `Both` company may contain either account type.

## account_features

```text
id
account_id
feature
timestamps
unique(account_id, feature)
```

The UI filters accounts by feature and the backend must enforce the same rule.

## provider_fee_tiers

Customer fee rules are feature-based:

```text
id
company_id
feature
amount_from
amount_to
fee_type: FIXED | PERCENTAGE
fee_value DECIMAL(18,4)
additional_fee_type: FIXED | PERCENTAGE
additional_fee_value DECIMAL(18,4)
is_active
timestamps
```

Lookup:

```text
company_id + feature + amount range
```

## agent_commission_tiers

Agent commission is **not feature-based**. A provider uses one amount range row containing both OUT and IN values, matching real provider tables that show Send/Receive commission side by side.

```text
id
company_id
amount_from
amount_to
commission_type: FIXED | PERCENTAGE
out_commission_value DECIMAL(18,4)
in_commission_value DECIMAL(18,4)
is_active
timestamps
```

There is no `feature` column and no `direction` column in this tier table.

Lookup:

```text
company_id + amount range
```

Commission direction is derived from the agent account's **principal balance movement before commission is added**:

```text
principal money leaves agent account -> OUT -> out_commission_value
principal money enters agent account -> IN  -> in_commission_value
```

A tier is only used when the transaction account is `PAY` and `is_agent=true`. Bank providers must not have usable agent commission tiers.

## agent_commission_entries

Actual earned commission is recorded separately for audit:

```text
id
transaction_id
account_id
company_id
agent_commission_tier_id nullable
direction: IN | OUT
base_amount
calculation_type: FIXED | PERCENTAGE
configured_value DECIMAL(18,4)
commission_amount
status: EARNED | REVERSED
reversed_at nullable
reversed_by nullable
created_at
```

There is no feature column. Cash In cancellation retains the audit row and marks the earning `REVERSED` when the financial effect is reversed.

`agent_commission_entries` is the source of truth for earned commission. `transactions` does not store duplicate `commission_amount`, `receive_commission_amount`, or `payout_commission_amount` columns. Response/UI summary values may be derived from the audit entries.

## transfer_fee_tiers

Transfer customer fee is route-based and separate from agent commission:

```text
id
company_from_id
company_to_id
amount_from
amount_to
fee_type
fee_value DECIMAL(18,4)
additional_fee_type
additional_fee_value DECIMAL(18,4)
is_active
timestamps
```

## exchange_rates

```text
id
company_id nullable
base_currency
quote_currency
base_amount
buy_rate
sell_rate
is_active
timestamps
```

A company-specific active rate is preferred; a global (`company_id = null`) rate may be used as fallback.

## Agent commission movement by transaction

Agent commission is calculated from actual account movement, regardless of feature name:

```text
Cash In   : selected provider account principal decreases -> OUT
Cash Out  : selected provider account principal increases -> IN
Transfer  : source account -> OUT; destination account -> IN
Exchange  : derive IN/OUT from the selected account's actual principal delta
```

Commission is added after the principal direction is established. Example:

```text
Principal OUT  -100,000
Commission         +200
Net change       -99,800
Direction remains OUT.
```

## Transaction responsibilities

### Cash In

- account must be active and support `cash_in`
- customer fee: `provider_fee_tiers` for `cash_in`
- agent commission: OUT/IN movement rule above (normally OUT in current cash-in flow)
- teller submits denominations/handoff
- status starts `PENDING_CASHIER_CONFIRM`
- Cashier confirms or cancels
- confirmation moves teller handoff to main vault
- cancellation reverses financial effects and agent earning audit

### Cash Out

- account must be active and support `cash_out`
- customer fee: `provider_fee_tiers` for `cash_out`
- agent commission: movement rule (normally IN in current cash-out flow)
- teller denomination validation remains required
- completes immediately after validation

### Send Money / Receive Money

- feature assignments control account visibility/eligibility
- provider customer fee remains feature-based
- agent commission is **not** based on these feature names; it uses account movement.

### Transfer

- selected source and destination accounts must be active, different, and support `transfer`
- customer fee: `transfer_fee_tiers`
- source PAY agent account: OUT commission
- destination PAY agent account: IN commission
- both sides are evaluated independently
- bank/non-agent accounts receive zero commission

### Exchange

- account must be active and support `exchange`
- conversion uses `exchange_rates`
- agent commission uses actual selected account principal movement, not `cash_in`/`cash_out` feature tiers

## Removed concepts

The final project must not create or depend on:

```text
service_types
service_types.operation
accounts.service_type_id
commission_tiers.service_type_id
accounts.commission_rate
deposit_* / withdraw_* duplicated fee columns
agent_commission_tiers.feature
legacy fee API aliases
legacy migration/backfill code
```

## Demo / migration policy

The system has not been delivered to the customer yet. Demo data may be rebuilt.

```bash
docker compose exec -T app php artisan migrate:fresh --seed --force
```

Migrations may be merged/restructured for a clean final schema. Demo seeders must follow the final tables and must never create Bank agent accounts.
