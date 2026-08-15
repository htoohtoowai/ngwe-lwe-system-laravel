# Final Demo Architecture Cleanup

The project is designed for a fresh database and customer demo. Legacy Service Type upgrade/backfill compatibility is intentionally removed.

## Pricing tables

```text
provider_fee_tiers
  company + feature + amount range
  fee type/value
  additional fee type/value

agent_commission_tiers
  company + amount range
  one commission type
  OUT value
  IN value

transfer_fee_tiers
  from company + to company + amount range
  fee type/value
  additional fee type/value
```

All configurable values support four decimal places and use `FIXED | PERCENTAGE`.

## Account model

Accounts use `account_type = PAY | BANK` and a generic `account_identifier`. The same identifier can be reused by different providers but not twice under the same provider.

Only PAY accounts may be marked `is_agent=true`.

## Agent commission audit

Agent commission is determined by principal account movement, not account feature. OUT and IN values come from the same provider amount-tier row. Each actual earning is snapshotted to `agent_commission_entries`; reversal keeps the row and changes its status.

## Validation target

For demo/local verification:

```bash
docker compose exec -T app php artisan migrate:fresh --seed --force
docker compose exec -T app php artisan migrate:status
php artisan test
npm run build

# Optional MySQL integration suite
./scripts/test-mysql.sh
```
