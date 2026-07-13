<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoUserSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password123';

    private const DEMO_USERS = [
        [
            'username' => 'owner',
            'email' => 'owner@ngwe-lwe.local',
            'full_name' => 'Demo Owner',
            'role' => 'owner',
            'pin' => '1111',
        ],
        [
            'username' => 'cashier',
            'email' => 'cashier@ngwe-lwe.local',
            'full_name' => 'Demo Cashier',
            'role' => 'cashier',
            'pin' => '2222',
        ],
        [
            'username' => 'employee',
            'email' => 'employee@ngwe-lwe.local',
            'full_name' => 'Demo Employee',
            'role' => 'employee',
            'pin' => '3333',
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo users can only be seeded in local or testing environments.');
        }

        foreach (self::DEMO_USERS as $seed) {
            User::query()->updateOrCreate(
                ['username' => $seed['username']],
                [
                    'name' => $seed['full_name'],
                    'email' => $seed['email'],
                    'full_name' => $seed['full_name'],
                    'role' => $seed['role'],
                    'is_active' => true,
                    'auth_version' => 0,
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'pin_hash' => Hash::make($seed['pin']),
                ],
            );
        }
    }
}
