<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

class UserRepository
{
    public function all(bool $includeInactive = false): Collection
    {
        return User::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('full_name')
            ->get();
    }

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

    /**
     * @param  array{
     *   username:string,
     *   full_name:string,
     *   role:string,
     *   password:string,
     *   email?:string|null,
     *   pin?:string|null,
     *   is_active?:bool
     * }  $data
     */
    public function create(array $data): User
    {
        return User::query()->create([
            'name' => $data['full_name'],
            'email' => $data['email'] ?? $data['username'].'@ngwe-lwe.local',
            'username' => $data['username'],
            'full_name' => $data['full_name'],
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
            'auth_version' => 0,
            'password' => Hash::make($data['password']),
            'pin_hash' => isset($data['pin']) ? Hash::make($data['pin']) : null,
        ])->refresh();
    }

    /**
     * @param  array{
     *   username?:string,
     *   full_name?:string,
     *   role?:string,
     *   password?:string,
     *   email?:string|null,
     *   pin?:string|null,
     *   is_active?:bool
     * }  $data
     */
    public function update(User $user, array $data): User
    {
        $authSensitive = false;

        if (array_key_exists('full_name', $data)) {
            $user->name = $data['full_name'];
            $user->full_name = $data['full_name'];
        }
        if (array_key_exists('email', $data)) {
            $user->email = $data['email'] ?? $user->username.'@ngwe-lwe.local';
        }
        if (array_key_exists('username', $data)) {
            $user->username = $data['username'];
            if (! array_key_exists('email', $data)) {
                $user->email = $data['username'].'@ngwe-lwe.local';
            }
            $authSensitive = true;
        }
        if (array_key_exists('role', $data)) {
            $user->role = $data['role'];
            $authSensitive = true;
        }
        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'];
            $authSensitive = true;
        }
        if (array_key_exists('password', $data)) {
            $user->password = Hash::make($data['password']);
            $authSensitive = true;
        }
        if (array_key_exists('pin', $data)) {
            $user->pin_hash = $data['pin'] === null ? null : Hash::make($data['pin']);
        }

        if ($authSensitive) {
            $user->auth_version = ((int) $user->auth_version) + 1;
        }

        $user->save();

        return $user->refresh();
    }

    public function deactivate(User $user): User
    {
        $user->is_active = false;
        $user->auth_version = ((int) $user->auth_version) + 1;
        $user->save();

        return $user->refresh();
    }

    public function revokeAuth(int $id): bool
    {
        return User::query()
            ->whereKey($id)
            ->increment('auth_version') > 0;
    }
}
