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
            'username' => 'owner',
            'full_name' => 'Owner Name',
            'role' => 'owner',
            'is_active' => true,
            'password' => Hash::make('admin123'),
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'owner',
            'password' => 'admin123',
        ])
            ->assertOk()
            ->assertJsonPath('user.username', 'owner')
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonMissingPath('user.password')
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'username' => 'employee',
            'is_active' => false,
            'password' => Hash::make('employee123'),
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'employee',
            'password' => 'employee123',
        ])->assertUnauthorized();
    }

    public function test_role_middleware_rejects_wrong_role(): void
    {
        $user = User::factory()->create([
            'username' => 'employee',
            'role' => 'employee',
            'is_active' => true,
            'password' => Hash::make('employee123'),
        ]);

        $token = app(NgweLweTokenService::class)->create($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/owner/status')
            ->assertForbidden();
    }
}
