<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('user management tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('u', 32));
    }

    public function test_owner_can_create_list_update_and_deactivate_staff_user(): void
    {
        $owner = User::factory()->create([
            'username' => 'owner',
            'full_name' => 'Owner User',
            'role' => 'owner',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
        $token = $this->tokenFor($owner);

        $userId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/users', [
                'username' => 'new_cashier',
                'full_name' => 'New Cashier',
                'role' => 'cashier',
                'password' => 'password123',
                'pin' => '2468',
            ])
            ->assertCreated()
            ->assertJsonPath('data.username', 'new_cashier')
            ->assertJsonPath('data.role', 'cashier')
            ->assertJsonPath('data.has_pin', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.pin_hash')
            ->json('data.id');

        $created = User::query()->findOrFail($userId);
        $this->assertTrue(Hash::check('password123', $created->password));
        $this->assertTrue(Hash::check('2468', $created->pin_hash));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('data.0.username', 'new_cashier');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/users/'.$userId, [
                'full_name' => 'Senior Cashier',
                'role' => 'employee',
                'password' => 'newpass123',
                'pin' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Senior Cashier')
            ->assertJsonPath('data.role', 'employee')
            ->assertJsonPath('data.has_pin', false)
            ->assertJsonPath('data.auth_version', 1);

        $created->refresh();
        $this->assertTrue(Hash::check('newpass123', $created->password));
        $this->assertNull($created->pin_hash);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/users/'.$userId)
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.auth_version', 2);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'owner');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/users?include_inactive=true')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_non_owner_cannot_manage_users(): void
    {
        $employee = User::factory()->create([
            'username' => 'employee',
            'role' => 'employee',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($employee))
            ->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_owner_cannot_deactivate_self(): void
    {
        $owner = User::factory()->create([
            'username' => 'owner',
            'role' => 'owner',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson('/api/users/'.$owner->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Owners cannot deactivate their own active session.');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->patchJson('/api/users/'.$owner->id, ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Owners cannot deactivate their own active session.');
    }

    public function test_deactivating_user_revokes_existing_token(): void
    {
        $owner = User::factory()->create([
            'username' => 'owner',
            'role' => 'owner',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
        $employee = User::factory()->create([
            'username' => 'employee',
            'role' => 'employee',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
        $employeeToken = $this->tokenFor($employee);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson('/api/users/'.$employee->id)
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    private function tokenFor(User $user): string
    {
        return app(NgweLweTokenService::class)->create($user);
    }
}
