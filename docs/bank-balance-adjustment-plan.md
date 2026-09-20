# Bank Digital Balance Adjustment Plan

## Purpose

This feature adjusts the system's stored bank digital balance when a Bank account is used as the OUT side of a Transfer.

It is not a customer fee feature. Customer transfer fees already exist in `transfer_fee_tiers` and must remain separate.

Example:

```text
Source account: KBZ Bank BANK account
Transfer direction: OUT
Transfer amount: 100,000
Bank digital adjustment: 20

Account balance deduction: 100,020
Transaction balance_change: -100,020
```

## Apply Rule

Apply bank digital balance adjustment only when all conditions are true:

```text
companies.category = Bank
accounts.account_type = BANK
transfer direction = OUT
```

Do not apply this adjustment to:

```text
PAY accounts
agent commission
customer transfer fee
cash-in
cash-out
send-money
receive-money
exchange
cash denomination / teller float movements
```

## Database Changes

Create a new table:

```text
bank_balance_adjustment_tiers
```

Columns:

```text
id
company_id -> companies.id
destination_company_id -> companies.id nullable
channel: mobile | internet_banking | branch | agent | any
transfer_method: own_account | internal | fast | local_remittance | cbm_net | any
is_same_location nullable boolean
amount_from DECIMAL(18,2)
amount_to DECIMAL(18,2)
adjustment_type: FIXED | PERCENTAGE
adjustment_value DECIMAL(18,4)
fixed_extra_amount DECIMAL(18,2) default 0
minimum_adjustment DECIMAL(18,2) nullable
maximum_adjustment DECIMAL(18,2) nullable
source_note nullable text
is_active boolean
timestamps
```

Add snapshot columns to `transactions`:

```text
bank_balance_adjustment_tier_id nullable
bank_balance_adjustment_amount DECIMAL(18,2) default 0
bank_transfer_method nullable string
bank_transfer_channel nullable string
is_same_location nullable boolean
```

Snapshot fields are required so old transactions keep the original adjustment even if an admin changes the rule later.

## Calculation

Formula:

```text
base = FIXED value OR amount * percentage / 100
adjustment = base + fixed_extra_amount
adjustment = max(adjustment, minimum_adjustment) when minimum exists
adjustment = min(adjustment, maximum_adjustment) when maximum exists
```

Transfer OUT debit:

```text
source_debit = transfer_amount
    + existing customer fee when fee is paid from source account
    + bank_balance_adjustment_amount
```

`customer_fee`, `fee_account_id`, and agent commission calculation remain unchanged.

## Admin UI

Add a new section in Admin -> Fees:

```text
Bank Balance Adjustments
```

Admin can create/edit:

```text
Bank company
Destination company optional
Customer type
Channel
Transfer method
Same location / Different location / Any
Amount range
Fixed or Percentage
Adjustment value
Fixed extra amount
Minimum adjustment
Maximum adjustment
Source note
Active / inactive
```

The UI should show a sample calculation preview so the admin can verify rules such as:

```text
0.02% of 100,000 = 20
0.01% minimum 2,000 = 2,000
0.15% + 500 with minimum 200
```

## Teller Transfer UI

When the selected OUT/source account is a BANK account under a Bank company, require:

```text
Transfer method
Channel
Customer type
Same location / Different location
```

For KBZ, Teller must be able to select methods such as:

```text
Internal
Fast
```

The transfer review must show:

```text
Transfer amount
Customer fee
Bank digital adjustment
Total deducted from source bank balance
Matched adjustment rule
```

Example review:

```text
Transfer amount: 100,000
Customer fee: 0
Bank digital adjustment: 20
Total bank deduction: 100,020
Rule: KBZ Bank / Internal / Mobile / Same location / 0.02%
```

If no active matching adjustment rule exists, block submission and tell the Teller to ask Admin to configure the bank balance adjustment rule. This prevents silent balance errors.

## Backend Changes

Add:

```text
BankBalanceAdjustmentTier model
BankBalanceAdjustmentTierRepository
BankBalanceAdjustmentCalculator service
BankBalanceAdjustmentTierRequest
AdminFeeController actions for create/update/delete/list
```

Update `TransferRequest` to accept and validate:

```text
bank_transfer_method
bank_transfer_channel
is_same_location
```

Update `TransactionService::createTransfer`:

```text
1. Detect whether the source account is BANK and company category is Bank.
2. If yes, require bank adjustment inputs.
3. Resolve the active adjustment tier.
4. Add adjustment amount to the source account debit.
5. Save snapshot fields on the transaction.
6. Keep customer fee and agent commission logic unchanged.
```

## Seed Data

Use official/web-searched values as editable defaults where available.

Seed examples:

```text
KBZ mobile internal: 0.02%
KBZ mobile fast: 0.02%
AYA other AYA account: 0.0125%, min 200, max 10,000
AYA interbank: 0.01%, min 3,000
AYA fast interbank: fixed tiers 3,000 / 5,000
CB local remittance: percentage + fixed extra + minimum
Yoma same township: fixed 0
Yoma different township: 0.025%
```

Keep all seeded data editable because bank fees can change.

## Tests

Backend tests:

```text
BANK + Bank company + OUT applies adjustment.
PAY account does not apply adjustment.
BANK destination/IN side does not apply adjustment.
Customer transfer fee still uses transfer_fee_tiers.
Agent commission still uses agent_commission_tiers.
Fixed, percentage, minimum, maximum, and fixed-extra formulas work.
No matching rule blocks bank OUT transfer.
Transaction snapshot persists selected method, location, tier, and amount.
```

Frontend/build checks:

```text
npm run build
php artisan test
php artisan migrate --force
```

Local verification:

```text
Run app at http://127.0.0.1:8001
Create a KBZ BANK OUT transfer for 100,000
Confirm account balance decreases by 100,020 when the matching 0.02% rule is active
```
