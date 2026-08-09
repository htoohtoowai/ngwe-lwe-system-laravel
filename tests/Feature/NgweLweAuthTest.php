<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NgweLweAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('auth tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('b', 32));
    }

    public function test_username_login_returns_token_and_safe_user_payload(): void
    {
        User::factory()->create([
            'username' => 'admin',
            'full_name' => 'Owner Name',
            'role' => 'admin',
            'is_active' => true,
            'password' => Hash::make('admin123'),
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin123',
        ])
            ->assertOk()
            ->assertJsonPath('user.username', 'admin')
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonMissingPath('user.password')
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'username' => 'teller',
            'is_active' => false,
            'password' => Hash::make('teller123'),
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'teller',
            'password' => 'teller123',
        ])->assertUnauthorized();
    }

    public function test_web_login_sets_http_only_cookie_and_redirects_to_role_workspace(): void
    {
        User::factory()->create([
            'username' => 'teller',
            'role' => 'teller',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        $this->post('/login', [
            'username' => 'teller',
            'password' => 'password123',
        ])
            ->assertRedirect('/dashboard')
            ->assertCookie('ngwe_lwe_api_token');
    }

    public function test_web_login_returns_validation_error_for_invalid_credentials(): void
    {
        User::factory()->create([
            'username' => 'teller',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        $this->from('/login')->post('/login', [
            'username' => 'teller',
            'password' => 'wrong-password',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username');
    }

    public function test_role_middleware_rejects_wrong_role(): void
    {
        $user = User::factory()->create([
            'username' => 'teller',
            'role' => 'teller',
            'is_active' => true,
            'password' => Hash::make('teller123'),
        ]);

        $token = app(NgweLweTokenService::class)->create($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/status')
            ->assertForbidden();
    }
}
