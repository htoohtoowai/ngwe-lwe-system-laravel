<?php

namespace App\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;
use Stringable;

#[Fillable([
    'user_id',
    'actor_name',
    'actor_role',
    'action',
    'category',
    'module',
    'status',
    'entity_type',
    'entity_id',
    'description',
    'details',
    'old_values',
    'new_values',
    'changed_fields',
    'ip_address',
    'user_agent',
    'route',
    'http_method',
    'request_id',
    'failure_reason',
])]
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'pin',
        'pin_hash',
        'token',
        'access_token',
        'refresh_token',
        'remember_token',
        'secret',
        'authorization',
        'cookie',
    ];

    protected static function booted(): void
    {
        static::creating(function (ActivityLog $log): void {
            $request = app()->bound('request') ? request() : null;
            $actor = auth()->user();

            $log->user_id ??= $actor?->id;
            $log->actor_name ??= $actor?->full_name ?? $actor?->username;
            $log->actor_role ??= $actor?->role;
            $log->category = $log->category ?: self::inferCategory((string) $log->action, (string) $log->entity_type);
            $log->module = $log->module ?: self::inferModule((string) $log->entity_type, (string) $log->action);
            $log->status = $log->status ?: 'success';
            $log->description ??= self::defaultDescription((string) $log->action, (string) $log->entity_type, $log->entity_id);

            if ($request !== null) {
                $log->ip_address ??= $request->ip();
                $log->user_agent ??= $request->userAgent();
                $log->route ??= $request->route()?->getName() ?? $request->path();
                $log->http_method ??= $request->method();

                $requestId = $request->headers->get('X-Request-ID')
                    ?: $request->attributes->get('_audit_request_id');
                if (! is_string($requestId) || $requestId === '') {
                    $requestId = (string) Str::uuid();
                    $request->attributes->set('_audit_request_id', $requestId);
                }
                $log->request_id ??= $requestId;
            }

            $log->details = self::sanitize($log->details);
            $log->old_values = self::sanitize($log->old_values);
            $log->new_values = self::sanitize($log->new_values);
            $log->changed_fields = self::sanitize($log->changed_fields);
        });

        // Audit evidence must be append-only from the application layer.
        static::updating(fn (): never => throw new LogicException('Activity audit logs are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Activity audit logs cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_fields' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = self::sanitize($childValue, is_string($childKey) ? $childKey : null);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return self::sanitize((array) $value);
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private static function inferCategory(string $action, string $entityType): string
    {
        if (str_contains($action, 'login') || str_contains($action, 'logout') || str_contains($action, 'password') || str_contains($action, 'pin')) {
            return 'authentication';
        }

        if ($action === 'balance_adjust' || str_contains($action, 'adjustment')) {
            return 'financial';
        }

        if ($entityType === 'transaction') {
            return 'transactions';
        }

        if (str_contains($entityType, 'vault') || str_contains($entityType, 'float')) {
            return 'vault_float';
        }

        if (in_array($entityType, ['user', 'role', 'permission'], true)) {
            return 'users_permissions';
        }

        if (in_array($entityType, ['account', 'company', 'exchange_rate', 'provider_fee_tier', 'agent_commission_tier', 'transfer_fee_tier'], true)) {
            return 'master_data';
        }

        return 'system';
    }

    private static function inferModule(string $entityType, string $action): string
    {
        if ($entityType !== '') {
            return $entityType;
        }

        return str_contains($action, 'login') || str_contains($action, 'logout') ? 'authentication' : 'system';
    }

    private static function defaultDescription(string $action, string $entityType, mixed $entityId): string
    {
        $label = trim(str_replace('_', ' ', $action));
        $subject = trim(str_replace('_', ' ', $entityType));
        $id = $entityId !== null ? ' #'.$entityId : '';

        return ucfirst($label).($subject !== '' ? ' · '.$subject.$id : '');
    }
}
