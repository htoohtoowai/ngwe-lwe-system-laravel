<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class SystemActivityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('system activity audit tests');
        parent::setUp();
    }

    public function test_successful_login_and_logout_are_audited(): void
    {
        $user = User::query()->create([
            'name' => 'Audit Admin',
            'full_name' => 'Audit Admin',
            'username' => 'audit-admin',
            'email' => 'audit-admin@example.test',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'is_active' => true,
            'auth_version' => 0,
        ]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password123',
        ])->assertRedirect('/admin');

        $login = ActivityLog::query()->where('action', 'login')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame('authentication', $login->category);
        $this->assertSame('success', $login->status);
        $this->assertNotEmpty($login->request_id);

        $this->post('/logout')->assertRedirect('/login');

        $logout = ActivityLog::query()->where('action', 'logout')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $logout->user_id);
        $this->assertSame('authentication', $logout->category);
    }

    public function test_failed_login_is_audited_without_requiring_a_user_record(): void
    {
        $this->post('/login', [
            'username' => 'unknown-user',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');

        $log = ActivityLog::query()->where('action', 'login_failed')->latest('id')->firstOrFail();
        $this->assertNull($log->user_id);
        $this->assertSame('unknown-user', $log->actor_name);
        $this->assertSame('failed', $log->status);
        $this->assertSame('Invalid credentials', $log->failure_reason);
    }

    public function test_admin_crud_is_audited_with_before_and_after_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/actions/companies', [
            'name' => 'Audit Pay',
            'category' => 'Pay',
            'is_active' => true,
        ])->assertRedirect();

        $company = Company::query()->where('name', 'Audit Pay')->firstOrFail();
        $created = ActivityLog::query()
            ->where('entity_type', 'company')
            ->where('entity_id', $company->id)
            ->where('action', 'create')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $created->user_id);
        $this->assertSame('master_data', $created->category);
        $this->assertSame('Audit Pay', $created->new_values['name'] ?? null);

        $this->actingAs($admin)->patch('/admin/actions/companies/'.$company->id, [
            'name' => 'Audit Pay Updated',
            'category' => 'Pay',
            'is_active' => true,
        ])->assertRedirect();

        $updated = ActivityLog::query()
            ->where('entity_type', 'company')
            ->where('entity_id', $company->id)
            ->where('action', 'update')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Audit Pay', $updated->old_values['name'] ?? null);
        $this->assertSame('Audit Pay Updated', $updated->new_values['name'] ?? null);
        $this->assertContains('name', $updated->changed_fields ?? []);
    }

    public function test_password_values_are_redacted_and_audit_rows_are_immutable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'teller']);

        $this->actingAs($admin)->post('/admin/actions/users/'.$target->id.'/reset-password', [
            'new_password' => 'new-password-123',
        ])->assertRedirect();

        $log = ActivityLog::query()
            ->where('entity_type', 'user')
            ->where('entity_id', $target->id)
            ->where('action', 'password_changed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('[REDACTED]', $log->old_values['password'] ?? null);
        $this->assertSame('[REDACTED]', $log->new_values['password'] ?? null);
        $this->assertStringNotContainsString('new-password-123', json_encode($log->toArray()));

        $this->expectException(LogicException::class);
        $log->update(['description' => 'tampered']);
    }

    public function test_admin_audit_screen_is_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teller = User::factory()->create(['role' => 'teller']);

        $this->actingAs($admin)->get('/admin/audit-logs')->assertOk();
        $this->actingAs($teller)->get('/admin/audit-logs')->assertRedirect('/dashboard');
    }
}
