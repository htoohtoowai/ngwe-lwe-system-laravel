<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Auth\AuthenticationException;
use JsonException;

class NgweLweTokenService
{
    public function __construct(
        private readonly ?UserRepository $users = null,
    ) {}

    public function create(User $user): string
    {
        $payload = json_encode([
            'user_id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'auth_version' => (int) ($user->auth_version ?? 0),
            'exp' => time() + (int) config('ngwe_lwe.auth.token_ttl_seconds', 86400),
        ], JSON_THROW_ON_ERROR);

        return $payload.'|'.$this->signature($payload);
    }

    public function decode(string $token): array
    {
        [$payload, $signature] = $this->splitToken($token);
        $expected = $this->signature($payload);

        if (! hash_equals($expected, $signature)) {
            throw new AuthenticationException('Invalid token');
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AuthenticationException('Invalid token', previous: $e);
        }

        if (! is_array($decoded) || (int) ($decoded['exp'] ?? 0) < time()) {
            throw new AuthenticationException('Token expired');
        }

        return $decoded;
    }

    public function userFromBearer(?string $authorization): User
    {
        if ($authorization === null || ! str_starts_with($authorization, 'Bearer ')) {
            throw new AuthenticationException('Missing Bearer token');
        }

        $payload = $this->decode(substr($authorization, 7));
        $user = ($this->users ?? app(UserRepository::class))->findActiveByUsername((string) ($payload['username'] ?? ''));

        if (
            $user === null
            || (int) $user->id !== (int) ($payload['user_id'] ?? 0)
            || (int) ($user->auth_version ?? 0) !== (int) ($payload['auth_version'] ?? 0)
        ) {
            throw new AuthenticationException('User inactive, missing, or token revoked');
        }

        return $user;
    }

    private function splitToken(string $token): array
    {
        $parts = explode('|', $token, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new AuthenticationException('Invalid token');
        }

        return $parts;
    }

    private function signature(string $payload): string
    {
        $secret = (string) config('ngwe_lwe.auth.secret');

        if (trim($secret) === '' || in_array(strtolower(trim($secret)), ['your_secret_key_here', 'change-me', 'secret'], true)) {
            throw new AuthenticationException('Auth secret is not configured');
        }

        return hash_hmac('sha256', $payload, $secret);
    }
}
