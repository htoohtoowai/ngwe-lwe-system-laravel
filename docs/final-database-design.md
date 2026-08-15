# Final Database Design (Demo Baseline)

This document summarizes the final business-facing schema after the pre-demo restructure.

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

```text
id
company_id -> companies.id
account_name
account_type: PAY | BANK
account_identifier
balance DECIMAL(18,2)
is_active
is_fee_account
is_agent
timestamps

UNIQUE(company_id, account_identifier)
```

`account_identifier` is a phone number for PAY accounts and an account number for BANK accounts. BANK accounts cannot be agents. The same identifier may exist under different providers.

Example under KBZPay:

```text
09256149967  normal PAY account
09256149968  normal PAY account
09256149969  normal/fee PAY account
09256149970  agent PAY account
09256149971  agent PAY account
```

## account_features

```text
id
account_id -> accounts.id
feature: cash_in | cash_out | send_money | receive_money | transfer | exchange
timestamps

UNIQUE(account_id, feature)
```

## provider_fee_tiers

```text
id
company_id
feature
amount_from DECIMAL(18,2)
amount_to DECIMAL(18,2)
fee_type: FIXED | PERCENTAGE
fee_value DECIMAL(18,4)
additional_fee_type: FIXED | PERCENTAGE
additional_fee_value DECIMAL(18,4)
is_active
timestamps
```

## agent_commission_tiers

```text
id
company_id
amount_from DECIMAL(18,2)
amount_to DECIMAL(18,2)
commission_type: FIXED | PERCENTAGE
out_commission_value DECIMAL(18,4)
in_commission_value DECIMAL(18,4)
is_active
timestamps
```

No feature column. One amount-range row stores both provider OUT/Send and IN/Receive values.

## agent_commission_entries

```text
id
transaction_id
account_id
company_id
agent_commission_tier_id nullable
direction: IN | OUT
base_amount DECIMAL(18,2)
calculation_type: FIXED | PERCENTAGE
configured_value DECIMAL(18,4)
commission_amount DECIMAL(18,2)
status: EARNED | REVERSED
reversed_at nullable
reversed_by nullable
created_at
```

## transfer_fee_tiers

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

## transactions

The transaction header remains the operational record for Cash In, Cash Out, Transfer and Exchange. Agent commission is **not duplicated** in transaction columns.

The following summary columns are intentionally absent:

```text
commission_amount
receive_commission_amount
payout_commission_amount
```

Actual earnings live in `agent_commission_entries`. API/UI convenience totals are derived from those entries.

## Agent commission rule

```text
PAY + is_agent=true + principal delta < 0 -> OUT value
PAY + is_agent=true + principal delta > 0 -> IN value
BANK or non-agent                           -> 0
```

The principal delta is determined before the earned commission is added.

## Vault / teller cash tables

The existing operational cash tables remain:

```text
cash_float_assignments
cash_float_denominations
note_denominations
vault_denomination_balances
vault_transactions
cash_denomination_logs
transaction_payment_denominations
```

## Audit/reporting tables

```text
activity_logs
daily_summary
daily_reconciliation_logs
transaction_notification_reads
```
