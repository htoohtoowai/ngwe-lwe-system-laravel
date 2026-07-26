<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashierOperationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('cashier operations page tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('q', 32));
    }

    public function test_cashier_can_open_each_operations_page(): void
    {
        [$cashier, $token] = $this->userWithToken('cashier');

        foreach ([
            '/cashier' => 'teller-entry-notifications',
            '/cashier/teller-entry-notifications' => 'teller-entry-notifications',
            '/cashier/main-vault-denomination-stock' => 'main-vault-denomination-stock',
            '/cashier/morning-issue' => 'morning-issue',
            '/cashier/end-of-day' => 'end-of-day',
            '/cashier/teller-entry-history' => 'teller-entry-history',
            '/cashier/main-vault-audit-log' => 'main-vault-audit-log',
        ] as $path => $section) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('cashier/Operations')
                    ->where('role', $cashier->role)
                    ->where('section', $section)
                );
        }
    }

    public function test_unknown_cashier_operations_page_is_not_found(): void
    {
        [, $token] = $this->userWithToken('cashier');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/cashier/not-a-page')
            ->assertNotFound();
    }

    public function test_cashier_profile_route_still_opens_profile_page(): void
    {
        [$cashier, $token] = $this->userWithToken('cashier');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/cashier/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('cashier/Profile')
                ->where('role', $cashier->role)
                ->where('user.id', $cashier->id)
            );
    }

    public function test_non_cashier_roles_cannot_open_cashier_operations_pages(): void
    {
        foreach (['admin', 'teller'] as $role) {
            [, $token] = $this->userWithToken($role);

            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get('/cashier/morning-issue')
                ->assertForbidden();
        }
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithToken(string $role): array
    {
        $user = User::factory()->create([
            'username' => $role.'_cashier_page_'.uniqid('', true),
            'full_name' => ucfirst($role).' User',
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        return [$user, app(NgweLweTokenService::class)->create($user)];
    }
}
