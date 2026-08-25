<?php

namespace App\Observers;

use App\Models\Account;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function created(Model $model): void
    {
        if (! $this->shouldAudit()) {
            return;
        }

        $this->audit->modelCreated($model);
    }

    public function updated(Model $model): void
    {
        if (! $this->shouldAudit()) {
            return;
        }

        $changes = $model->getChanges();
        unset($changes['updated_at']);

        if ($changes === []) {
            return;
        }

        // Account balances change continuously through normal transactions.
        // Those movements already have dedicated transaction/vault audit rows;
        // do not flood the CRUD audit with balance-only writes.
        if ($model instanceof Account && array_keys($changes) === ['balance']) {
            return;
        }

        $old = [];
        $new = [];
        foreach (array_keys($changes) as $field) {
            $old[$field] = $model->getRawOriginal($field);
            $new[$field] = $model->getAttribute($field);
        }

        $this->audit->modelUpdated(
            $model,
            $this->audit->auditableAttributes($old),
            $this->audit->auditableAttributes($new),
        );
    }

    public function deleted(Model $model): void
    {
        if (! $this->shouldAudit()) {
            return;
        }

        $this->audit->modelDeleted($model);
    }

    private function shouldAudit(): bool
    {
        return auth()->check();
    }
}
