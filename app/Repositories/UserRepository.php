<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    public function activeByRole(string $role): Collection
    {
        return User::query()
            ->where('role', $role)
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get();
    }

    public function findActive(int $id): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->find($id);
    }

    public function findByUsername(string $username): ?User
    {
        return User::query()
            ->where('username', $username)
            ->first();
    }

    public function findActiveByUsername(string $username): ?User
    {
        return User::query()
            ->where('username', $username)
            ->where('is_active', true)
            ->first();
    }

    public function revokeAuth(int $id): bool
    {
        return User::query()
            ->whereKey($id)
            ->increment('auth_version') > 0;
    }
}
