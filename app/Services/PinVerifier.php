<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Central PIN verification. Failed attempts are recorded without persisting
 * the submitted PIN itself.
 */
class PinVerifier
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function verify(User $user, ?string $pin): void
    {
        if ($pin === null || $pin === '') {
            $this->failed($user, 'PIN is required.');
            throw new InvalidArgumentException('PIN is required.');
        }

        if (empty($user->pin_hash)) {
            $this->failed($user, 'No PIN is configured.');
            throw new InvalidArgumentException('No PIN set. Please set your PIN first.');
        }

        if (! Hash::check($pin, (string) $user->pin_hash)) {
            $this->failed($user, 'Incorrect PIN.');
            throw new InvalidArgumentException('Incorrect PIN.');
        }
    }

    private function failed(User $user, string $reason): void
    {
        $this->audit->record(
            action: 'pin_verification_failed',
            category: 'authentication',
            module: 'authorization',
            entityType: 'user',
            entityId: $user->id,
            description: 'PIN verification failed',
            status: 'failed',
            failureReason: $reason,
            actor: $user,
        );
    }
}
