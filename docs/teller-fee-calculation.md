# Teller Company / Account / Feature Fee Calculation

ဤစာတမ်းသည် Service Types ဖယ်ရှားပြီးနောက် Teller transaction fee နှင့် agent commission တွက်ချက်ပုံကို ရှင်းပြထားသည်။

## Final Data Relationship

```text
Company
  -> Accounts
       -> Account Features

Company + Feature + Amount
  -> Commission Tier
```

- `companies` သည် KBZPay, Wave Money, AYA Bank ကဲ့သို့ provider ကို ကိုယ်စားပြုသည်။
- `accounts.company_id` ဖြင့် account ကို provider နှင့် တိုက်ရိုက်ချိတ်သည်။
- `account_features` ဖြင့် account ကို `cash_in`, `cash_out`, `send_money`, `receive_money`, `transfer`, `exchange` feature များတွင် အသုံးပြုနိုင်/မနိုင် သတ်မှတ်သည်။
- `service_types`, `accounts.service_type_id`, `commission_tiers.service_type_id` မရှိတော့ပါ။

## Cash In / Cash Out Tier Lookup

Teller က account နှင့် amount ရွေးပြီးနောက် system သည် အောက်ပါ key ဖြင့် active tier ရှာသည်။

```text
account.company_id + transaction feature + amount
```

Amount သည် tier range အတွင်းပါရမည်။

```text
amount_from <= amount <= amount_to
```

- Cash In သည် `feature = cash_in` tier ကိုသုံးသည်။
- Cash Out သည် `feature = cash_out` tier ကိုသုံးသည်။
- Tier မတွေ့လျှင် customer fee နှင့် agent commission သည် `0 MMK` ဖြစ်သည်။

## Fee Calculation

`FIXED`:
- `fee_amount` ကို MMK amount အဖြစ် တိုက်ရိုက်သုံးသည်။
- ဥပမာ `FIXED 200` ဆိုလျှင် fee သည် `200 MMK` ဖြစ်သည်။

`PERCENTAGE`:
- Admin UI တွင် ရိုက်သည့် value သည် လူသုံး percentage value ဖြစ်သည်။
- `1 = 1%`
- `0.2 = 0.2%`
- `0.0001 = 0.0001%`

```text
raw fee = amount * (fee_amount / 100)
customer fee = MMK rounding(raw fee + additional fee)
```

MMK rounding တွင် minimum fee `100 MMK` ရှိသည်။ ထို့ကြောင့် raw fee သည် `100 MMK` အောက်ဖြစ်လျှင် final fee `100 MMK` ဖြစ်နိုင်သည်။

### Cash Out Example

`210,000 MMK` ကို `PERCENTAGE 0.2` ဖြင့်တွက်လျှင်:

```text
210,000 * (0.2 / 100) = 420 MMK
```

Final rounded fee သည် `400 MMK` ဖြစ်သည်။

`FIXED 0.2` ဆိုလျှင် `0.2 MMK` သာဖြစ်ပြီး minimum MMK rounding ကြောင့် final fee `100 MMK` ဖြစ်သည်။

## Agent Commission

Account ၏ `is_agent = true` ဖြစ်မှ commission တွက်သည်။

```text
company_id + feature + amount -> commission tier
```

- `FIXED`: `comm_amount` ကို MMK amount အဖြစ်သုံးသည်။
- `PERCENTAGE`: `amount * (comm_amount / 100)` ဖြင့်တွက်သည်။
- `is_agent = false` သို့မဟုတ် matching tier မရှိလျှင် commission သည် `0 MMK` ဖြစ်သည်။

Cash In, Cash Out, Send Money, Receive Money, Transfer နှင့် Exchange agent commission အားလုံးသည် provider အလိုက် `commission_tiers` မှယူသည်။

## Transfer And Exchange

Transfer customer fee:
- `transfer_fee_tiers.company_from_id + company_to_id + amount` ကိုသုံးသည်။
- Agent commission ကို သက်ဆိုင်ရာ account provider ၏ `commission_tiers` မှယူသည်။

Exchange:
- Currency conversion ကို `exchange_rates` မှယူသည်။
- Agent commission ကို account provider နှင့် settlement direction အလိုက် `cash_in` သို့မဟုတ် `cash_out` tier မှယူသည်။

## Manual Verification

1. Cash Out `FIXED 200` => `200 MMK`
2. Cash Out `FIXED 0.2` => minimum rounding ကြောင့် `100 MMK`
3. Cash Out `PERCENTAGE 0.2`, amount `210,000` => raw `420`, rounded `400 MMK`
4. Non-agent account => commission `0 MMK`
5. Agent account with matching company/feature tier => configured commission
6. Account feature မပါလျှင် သက်ဆိုင်ရာ Teller account list တွင် မပေါ်ရ
