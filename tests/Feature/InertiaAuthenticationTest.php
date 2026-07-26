<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InertiaAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('Inertia authentication tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('x', 32));
    }

    public function test_expired_token_redirects_inertia_page_requests_to_login(): void
    {
        $user = User::factory()->create([
            'username' => 'expired-owner',
            'role' => 'admin',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        config()->set('ngwe_lwe.auth.token_ttl_seconds', -1);
        $token = app(NgweLweTokenService::class)->create($user);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ])->get('/dashboard')
            ->assertRedirect(route('login'));
    }

    public function test_expired_token_still_returns_json_for_api_requests(): void
    {
        $user = User::factory()->create([
            'username' => 'expired-api-owner',
            'role' => 'admin',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        config()->set('ngwe_lwe.auth.token_ttl_seconds', -1);
        $token = app(NgweLweTokenService::class)->create($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Token expired');
    }
}
