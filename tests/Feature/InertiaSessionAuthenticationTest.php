<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InertiaSessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('Inertia session authentication tests');
        parent::setUp();
    }

    public function test_guest_is_redirected_to_login_for_protected_web_pages(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/cashier')->assertRedirect('/login');
        $this->get('/transactions/cash-in')->assertRedirect('/login');
    }

    public function test_login_uses_laravel_session_and_redirects_by_role(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'full_name' => 'Admin',
            'username' => 'admin-session',
            'email' => 'admin-session@example.test',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'is_active' => true,
            'auth_version' => 0,
        ]);

        $this->post('/login', [
            'username' => $admin->username,
            'password' => 'password123',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertOk();
    }

    public function test_logout_invalidates_authenticated_session(): void
    {
        $user = User::query()->create([
            'name' => 'Teller',
            'full_name' => 'Teller',
            'username' => 'teller-session',
            'email' => 'teller-session@example.test',
            'password' => Hash::make('password123'),
            'role' => 'teller',
            'is_active' => true,
            'auth_version' => 0,
        ]);

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_api_routes_are_not_registered(): void
    {
        $this->get('/api/companies')->assertNotFound();
        $this->post('/api/transactions/cash-in')->assertNotFound();
    }
}
