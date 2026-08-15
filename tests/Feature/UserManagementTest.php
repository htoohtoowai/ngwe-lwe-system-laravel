<?php

namespace Tests\Feature;

use App\Models\User;
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
    }

    public function test_admin_can_create_user_through_web_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/actions/users', [
            'username' => 'newteller',
            'email' => 'newteller@example.test',
            'full_name' => 'New Teller',
            'role' => 'teller',
            'password' => 'password123',
            'pin' => '3333',
            'is_active' => true,
        ])->assertRedirect();

        $user = User::query()->where('username', 'newteller')->firstOrFail();
        $this->assertSame('teller', $user->role);
        $this->assertTrue(Hash::check('password123', $user->password));
    }
}
