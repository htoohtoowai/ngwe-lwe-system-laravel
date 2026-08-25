<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /**
     * @param  array<string, mixed>|null  $details
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<int|string, mixed>|null  $changedFields
     */
    public function record(
        string $action,
        string $category,
        string $module,
        string $entityType = 'system',
        ?int $entityId = null,
        ?string $description = null,
        ?array $details = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $changedFields = null,
        string $status = 'success',
        ?string $failureReason = null,
        ?User $actor = null,
        ?int $userId = null,
        ?string $actorName = null,
        ?string $actorRole = null,
    ): ActivityLog {
        $actor ??= auth()->user();

        return ActivityLog::query()->create([
            'user_id' => $userId ?? $actor?->id,
            'actor_name' => $actorName ?? $actor?->full_name ?? $actor?->username,
            'actor_role' => $actorRole ?? $actor?->role,
            'action' => $action,
            'category' => $category,
            'module' => $module,
            'status' => $status,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'details' => $details,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_fields' => $changedFields,
            'failure_reason' => $failureReason,
        ]);
    }

    public function modelCreated(Model $model): ActivityLog
    {
        return $this->record(
            action: 'create',
            category: $this->categoryFor($model),
            module: $this->entityType($model),
            entityType: $this->entityType($model),
            entityId: $this->modelId($model),
            description: 'Created '.$this->subjectLabel($model),
            newValues: $this->auditableAttributes($model->getAttributes()),
            changedFields: array_keys($this->auditableAttributes($model->getAttributes())),
        );
    }

    /** @param array<string, mixed> $oldValues @param array<string, mixed> $newValues */
    public function modelUpdated(Model $model, array $oldValues, array $newValues): ActivityLog
    {
        $fields = array_values(array_unique(array_merge(array_keys($oldValues), array_keys($newValues))));

        return $this->record(
            action: $this->updateAction($model, $fields),
            category: $this->categoryFor($model),
            module: $this->entityType($model),
            entityType: $this->entityType($model),
            entityId: $this->modelId($model),
            description: 'Updated '.$this->subjectLabel($model),
            oldValues: $oldValues,
            newValues: $newValues,
            changedFields: $fields,
        );
    }

    public function modelDeleted(Model $model): ActivityLog
    {
        return $this->record(
            action: 'delete',
            category: $this->categoryFor($model),
            module: $this->entityType($model),
            entityType: $this->entityType($model),
            entityId: $this->modelId($model),
            description: 'Deleted '.$this->subjectLabel($model),
            oldValues: $this->auditableAttributes($model->getAttributes()),
        );
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function auditableAttributes(array $attributes): array
    {
        unset($attributes['created_at'], $attributes['updated_at']);

        return ActivityLog::sanitize($attributes);
    }

    private function categoryFor(Model $model): string
    {
        return $model instanceof User ? 'users_permissions' : 'master_data';
    }

    private function entityType(Model $model): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($model)) ?? class_basename($model));
    }

    private function modelId(Model $model): ?int
    {
        $key = $model->getKey();

        return is_numeric($key) ? (int) $key : null;
    }

    private function subjectLabel(Model $model): string
    {
        $type = str_replace('_', ' ', $this->entityType($model));
        $id = $this->modelId($model);

        return $type.($id !== null ? ' #'.$id : '');
    }

    /** @param array<int, string> $fields */
    private function updateAction(Model $model, array $fields): string
    {
        if ($model instanceof User) {
            if (in_array('password', $fields, true)) {
                return 'password_changed';
            }
            if (in_array('pin_hash', $fields, true)) {
                return 'pin_changed';
            }
            if (in_array('role', $fields, true)) {
                return 'role_changed';
            }
            if (in_array('is_active', $fields, true)) {
                return 'status_changed';
            }
        }

        if (in_array('is_active', $fields, true)) {
            return 'status_changed';
        }

        return 'update';
    }
}
