<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InertiaRoleRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('Inertia role route tests');
        parent::setUp();
    }

    public function test_admin_cashier_and_teller_use_web_routes_without_bearer_tokens(): void
    {
        $admin = $this->user('admin', 'admin-web');
        $cashier = $this->user('cashier', 'cashier-web');
        $teller = $this->user('teller', 'teller-web');

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($cashier)->get('/cashier')->assertOk();
        $this->actingAs($teller)->get('/dashboard')->assertOk();
        $this->actingAs($teller)->get('/transactions/cash-in')->assertOk();
        $this->actingAs($teller)->get('/transactions/cash-out')->assertOk();
        $this->actingAs($teller)->get('/transactions/transfer')->assertOk();
        $this->actingAs($teller)->get('/transactions/exchange')->assertOk();
    }

    private function user(string $role, string $username): User
    {
        return User::query()->create([
            'name' => ucfirst($role),
            'full_name' => ucfirst($role),
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'is_active' => true,
            'auth_version' => 0,
        ]);
    }
}
