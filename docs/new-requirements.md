# Final Requirements: Companies, Accounts and Transactions

ဤ project သည် database အသစ်မှစတင်မည်ဖြစ်ပြီး legacy Service Type schema သို့မဟုတ် data backfill မသုံးပါ။

## Technology

- Laravel
- Vue 3
- Inertia.js
- Tailwind CSS
- MySQL

Teller နှင့် Admin web flows များကို Inertia pages နှင့် Laravel web actions ဖြင့်အဓိကတည်ဆောက်မည်။

## Feature Enum

Feature list ကို database table မထားဘဲ PHP enum `AccountFeature` ဖြင့်ထိန်းမည်။

```text
cash_in
cash_out
send_money
receive_money
transfer
exchange
```

WST နှင့် P2P အမည်များမသုံးတော့ပါ။

```text
WST -> Send Money
P2P -> Receive Money
```

## Final Tables

### companies

Provider master data ဖြစ်သည်။

```text
id
name
logo_path
category: Pay | Bank | Both
is_active
timestamps
```

ဥပမာ KBZPay, Wave Money, AYA Bank, CB Bank။

### accounts

Provider ၏ real Pay/Bank account ဖြစ်သည်။

```text
id
company_id
account_name
phone_number
balance
is_active
is_fee_account
is_agent
timestamps
```

- Account ကို `company_id` ဖြင့် provider နှင့်တိုက်ရိုက်ချိတ်သည်။
- `is_agent = true` ဖြစ်မှ agent commission တွက်သည်။
- Account-level `commission_rate` မရှိပါ။ Commission source သည် tier တစ်ခုတည်းဖြစ်သည်။

### account_features

Account ကို ဘယ် transaction features တွင်အသုံးပြုနိုင်သည်ကိုသတ်မှတ်သည်။

```text
id
account_id
feature
timestamps
unique(account_id, feature)
```

Teller screen တစ်ခုတွင် သက်ဆိုင်ရာ feature ပါသည့် active accounts များသာပြမည်။

### commission_tiers

Cash In, Cash Out, Send Money, Receive Money နှင့် agent commission များအတွက် provider tier ဖြစ်သည်။

```text
id
company_id
feature
amount_from
amount_to
fee_type
fee_amount
comm_type
comm_amount
additional_fee_type
additional_fee_amount
is_active
created_at
```

Lookup key:

```text
company_id + feature + amount
amount_from <= amount <= amount_to
```

### transfer_fee_tiers

Transfer customer fee အတွက် provider route tier ဖြစ်သည်။

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
timestamps
```

Transfer agent commission ကို account provider ၏ `commission_tiers` မှယူမည်။

### exchange_rates

Currency conversion rate ဖြစ်သည်။

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

Exchange agent commission ကို account provider နှင့် settlement direction အလိုက် `cash_in` သို့မဟုတ် `cash_out` commission tier မှယူမည်။

## Calculation Rules

### FIXED

Configured amount ကို MMK တန်ဖိုးအဖြစ် တိုက်ရိုက်သုံးသည်။

```text
FIXED 200 = 200 MMK
```

### PERCENTAGE

Admin ရိုက်သည့် value သည် human percentage ဖြစ်သည်။

```text
1 = 1%
0.2 = 0.2%
0.0001 = 0.0001%
raw value = amount * (configured value / 100)
```

Customer fee ကို MMK rounding ပြုလုပ်ပြီး minimum `100 MMK` rule သုံးသည်။

Agent commission ကို:
- `is_agent = true` ဖြစ်မှတွက်မည်။
- matching provider/feature/amount tier မှ `comm_type + comm_amount` ကိုသုံးမည်။
- tier မရှိလျှင် သို့မဟုတ် non-agent account ဖြစ်လျှင် `0 MMK` ဖြစ်မည်။

## Transaction Responsibilities

### Cash In

- account feature: `cash_in`
- customer fee: matching `commission_tiers`
- agent commission: same provider/feature tier
- fee payment: cash သို့မဟုတ် fee account

### Cash Out

- account feature: `cash_out`
- customer fee: matching `commission_tiers`
- agent commission: same provider/feature tier
- teller/main vault denomination validation ကိုဆက်သုံးမည်

### Send Money / Receive Money

- account visibility ကို matching feature assignment ဖြင့်ထိန်းမည်
- fee နှင့် agent commission ကို provider feature tier မှယူမည်

### Transfer

- account feature: `transfer`
- customer fee: `transfer_fee_tiers`
- receive/payout agent commission: account provider ၏ `receive_money` နှင့် `send_money` tiers

### Exchange

- account feature: `exchange`
- conversion: `exchange_rates`
- agent commission: provider `cash_in` သို့မဟုတ် `cash_out` tier

## Removed Concepts

Final project တွင် အောက်ပါတို့ကို create မလုပ်ပါ။

```text
service_types
service_types.operation
accounts.service_type_id
commission_tiers.service_type_id
account commission_rate
deposit/withdraw duplicate tier columns
legacy fee API aliases
legacy migration/backfill code
```
