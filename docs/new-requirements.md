# New Requirement: Companies, Accounts, Fees, Transfer, Exchange

ဤစာတမ်းသည် final requirement အရ database design နှင့် calculation responsibility ကိုရှင်းပြထားသည်။ လက်ရှိ system တွင် `service_types` table သုံးနေသော်လည်း final requirement အရ `service_types` မလိုတော့ပါ။

## Current State

လက်ရှိ code/database တွင် အဓိက structure က အောက်ပါအတိုင်းဖြစ်နေသည်။

```text
companies
service_types
accounts
commission_tiers
exchange_rates
transactions
cash/vault tables
```

လက်ရှိ relation:

```text
company -> service_type -> account
commission_tiers -> service_type_id
```

လက်ရှိ issue:

- `service_types.operation` က CashIn / CashOut / Transfer / Exchange account visibility ကိုဆုံးဖြတ်နေသည်။
- Account တစ်ခုချင်းစီကို ဘယ် feature တွင်သုံးမလဲ မသတ်မှတ်နိုင်သေးပါ။
- Final requirement အရ WST/P2P ကို service type မဟုတ်ဘဲ feature name အဖြစ်ပြောင်းမည်။

## Final Concept

Final naming:

```text
WST -> Send Money
P2P -> Receive Money
```

Feature list ကို database table မထားဘဲ PHP enum/config ဖြင့်ထားမည်။

```text
cash_in
cash_out
send_money
receive_money
transfer
exchange
```

Final responsibility:

```text
companies
  Provider: KBZPay/KPay, AYA Bank, WavePay

accounts
  Company အောက်က real Pay/Bank account

account_features
  Account ကို ဘယ် feature တွေမှာသုံးမလဲ

commission_tiers
  Cash In, Cash Out, Send Money, Receive Money fee + agent commission

transfer_fee_tiers
  Transfer customer fee, company_from -> company_to အလိုက်

exchange_rates
  Exchange conversion rate
```

## Final Tables

### companies

```text
id
name
category
is_active
created_at
updated_at
```

### accounts

```text
id
company_id
account_name
phone_number / account_number
balance
is_agent
is_fee_account
is_active
created_at
updated_at
```

`accounts.company_id` သည် direct source ဖြစ်မည်။

```text
selected account -> company_id
```

### account_features

```text
id
account_id
feature
created_at
updated_at
```

`feature` သည် PHP enum/config value ဖြစ်မည်။

```text
cash_in
cash_out
send_money
receive_money
transfer
exchange
```

Account visibility:

```text
Cash In screen      -> account_features.feature = cash_in
Cash Out screen     -> account_features.feature = cash_out
Send Money screen   -> account_features.feature = send_money
Receive Money screen-> account_features.feature = receive_money
Transfer screen     -> account_features.feature = transfer
Exchange screen     -> account_features.feature = exchange
```

### commission_tiers

`commission_tiers` ကို အောက်ပါ 4 features အတွက်သုံးမည်။

```text
cash_in
cash_out
send_money
receive_money
```

Recommended final columns:

```text
id
company_id
feature
amount_from
amount_to

fee_type
fee_amount

additional_fee_type
additional_fee_amount

comm_type
comm_amount

is_active
created_at
updated_at
```

Lookup rule:

```text
selected account.company_id
+ selected feature
+ transaction amount
=> active commission_tiers row
```

Amount range:

```text
amount_from <= amount <= amount_to
```

Fee calculation:

```text
if fee_type == FIXED:
    base_fee = fee_amount

if fee_type == PERCENTAGE:
    base_fee = amount * (fee_amount / 100)

if additional_fee_type == FIXED:
    additional_fee = additional_fee_amount

if additional_fee_type == PERCENTAGE:
    additional_fee = amount * (additional_fee_amount / 100)

customer_fee = roundMmkFee(base_fee + additional_fee)
```

Percentage precision requirement:

- Percentage input fields must allow values down to `0.0001`.
- Admin UI number inputs for percentage-capable fee / additional fee / commission values must use `step="0.0001"`.
- Backend validation must accept at least 4 decimal places.
- Database amount columns that can store percentage values must support 4 decimal places, for example `decimal(18,4)`.
- `0.0001` means `0.0001%`, so calculation must use `value / 100`.

Example:

```text
amount = 1,000,000
fee_type = PERCENTAGE
fee_amount = 0.0001

raw fee = 1,000,000 * (0.0001 / 100) = 1 MMK
final customer fee = roundMmkFee(1)
```

Agent commission calculation:

```text
if account.is_agent == false:
    commission = 0

if account.is_agent == true:
    if comm_type == FIXED:
        commission = comm_amount

    if comm_type == PERCENTAGE:
        commission = amount * (comm_amount / 100)
```

### transfer_fee_tiers

Transfer customer fee ကို `commission_tiers` မှမတွက်ပါ။ Company route အလိုက် `transfer_fee_tiers` သုံးမည်။

```text
id
company_from_id
company_to_id
amount_from
amount_to

fee_type
fee_amount

additional_fee_type
additional_fee_amount

is_active
created_at
updated_at
```

Lookup rule:

```text
from_account.company_id
to_account.company_id
transaction amount
=> active transfer_fee_tiers row
```

Fee calculation:

```text
customer_fee = roundMmkFee(fee + additional_fee)
```

Transfer percentage precision uses the same rule:

```text
percentage value supports 0.0001
raw value = amount * (value / 100)
```

Transfer agent commission:

- If transfer uses an agent account and commission is required, agent commission should still come from `commission_tiers`.
- Customer transfer fee itself comes from `transfer_fee_tiers`.

### exchange_rates

Exchange conversion ကို fee tier နှင့်မရောပါ။

```text
id
company_id nullable
base_currency
quote_currency
base_amount
buy_rate
sell_rate
is_active
created_at
updated_at
```

Exchange calculation:

```text
THB -> MMK uses buy_rate
MMK -> THB uses sell_rate
effective_rate = rate / base_amount
```

Exchange agent commission:

- Exchange account is agent ဖြစ်လျှင် commission ပေးနိုင်သည်။
- Commission source သည် `commission_tiers` ဖြစ်မည်။
- Exchange conversion source သည် `exchange_rates` ဖြစ်မည်။
- `account.is_agent = false` ဖြစ်လျှင် commission သည် `0` ဖြစ်မည်။
- Provider lookup သည် `account.company_id + feature + amount` ကိုသုံးမည်။
- MMK -> THB သည် `cash_in` commission tier ကိုသုံးမည်။
- THB -> MMK သည် MMK settlement amount ဖြင့် `cash_out` commission tier ကိုသုံးမည်။

## Tables To Remove Or Deprecate

Final requirement အရ မလိုတော့သော table/columns:

```text
service_types
service_types.operation
accounts.service_type_id
commission_tiers.service_type_id
```

သို့သော်ချက်ချင်း drop မလုပ်သင့်ပါ။ Current code သည် `service_type_id` ပေါ်မှီနေသေးသောကြောင့် staged migration လိုသည်။

## Migration Path

1. Add `accounts.company_id`.
2. Backfill `accounts.company_id` from current `accounts.service_type_id -> service_types.company_id`.
3. Add `account_features` with PHP enum/config feature values.
4. Backfill account features from current `service_types.operation` and service type names:
   - `WST` -> `send_money`
   - `P2P` -> `receive_money`
   - existing CashIn/CashOut/Transfer/Exchange operations -> matching feature values
5. Add `commission_tiers.company_id` and `commission_tiers.feature`.
6. Backfill `commission_tiers` from existing `service_type_id`.
7. Add `transfer_fee_tiers`.
8. Update account filters to use `account_features`.
9. Update fee lookup to use:
   - `commission_tiers.company_id + feature` for Cash In / Cash Out / Send Money / Receive Money
   - `transfer_fee_tiers.company_from_id + company_to_id` for Transfer customer fee
   - `exchange_rates` for Exchange conversion
10. After tests pass and production data is verified, drop old `service_types` dependencies.

## Summary

Final design:

```text
companies
accounts
account_features
commission_tiers
transfer_fee_tiers
exchange_rates
transactions
cash/vault tables
```

`service_types` သည် final requirement အရမလိုတော့ပါ။ Feature list ကို PHP enum/config ဖြင့်ထိန်းမည်။ Account visibility ကို `account_features` ဖြင့်ထိန်းမည်။ Cash In / Cash Out / Send Money / Receive Money fee နှင့် agent commission ကို `commission_tiers` ဖြင့်တွက်မည်။ Transfer customer fee ကို `transfer_fee_tiers` ဖြင့်တွက်မည်။ Exchange conversion ကို `exchange_rates` ဖြင့်တွက်မည်။
