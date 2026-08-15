# Teller Fee and Agent Commission Calculation

## Customer fee

Customer fees remain feature-based:

```text
provider_fee_tiers
company + feature + amount range
```

`fee_type` and `additional_fee_type` are `FIXED | PERCENTAGE`; values support `0.0001`.

## Agent commission

Agent commission is independent of transaction feature.

Eligibility:

```text
account_type = PAY
AND is_agent = true
```

Tier lookup:

```text
agent_commission_tiers
company + amount range
```

The transaction service determines the selected agent account's principal delta before commission:

```text
delta < 0 -> OUT -> out_commission_value
delta > 0 -> IN  -> in_commission_value
```

The tier's single `commission_type` applies to both OUT and IN values. `PERCENTAGE` means:

```text
base_amount * (configured_value / 100)
```

Each positive earning writes an `agent_commission_entries` audit row containing direction, base amount, tier, calculation type/value and earned amount.

### Flow mapping in the current implementation

```text
Cash In   -> selected provider principal OUT
Cash Out  -> selected provider principal IN
Transfer  -> source OUT + destination IN
Exchange  -> direction derived from actual selected-account principal delta
```

Bank accounts and PAY non-agent accounts always receive zero agent commission.
