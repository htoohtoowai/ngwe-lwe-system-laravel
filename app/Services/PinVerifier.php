<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Ports Python VaultService._verify_pin (bcrypt PIN check).
 *
 * Failure surface:
 *   - InvalidArgumentException with "No PIN set" when the user has
 *     never called POST /api/auth/pin.
 *   - InvalidArgumentException with "Incorrect PIN" on mismatch.
 * Both map to HTTP 422 at the controller so callers get clear
 * validation-style feedback without leaking whether a PIN exists.
 */
class PinVerifier
{
    public function verify(User $user, ?string $pin): void
    {
        if ($pin === null || $pin === '') {
            throw new InvalidArgumentException('PIN is required.');
        }

        if (empty($user->pin_hash)) {
            throw new InvalidArgumentException('No PIN set. Please set your PIN first.');
        }

        if (! Hash::check($pin, (string) $user->pin_hash)) {
            throw new InvalidArgumentException('Incorrect PIN.');
        }
    }
}
