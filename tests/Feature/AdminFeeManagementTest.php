<?php

namespace Tests\Feature;

use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\User;
use App\Services\NgweLweTokenService;
use App\Services\TransferFeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminFeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('admin fee management tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('f', 32));
    }

    public function test_admin_fee_page_loads_provider_and_transfer_tiers(): void
    {
        [, $token] = $this->adminWithToken();
        $from = Company::query()->create(['name' => 'KBZPay', 'category' => 'Pay', 'is_active' => true]);
        $to = Company::query()->create(['name' => 'Wave Money', 'category' => 'Pay', 'is_active' => true]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/admin/fees/provider', $this->providerPayload($from->id))
            ->assertRedirect('/admin/fees/provider');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/admin/fees/transfer', $this->transferPayload($from->id, $to->id))
            ->assertRedirect('/admin/fees/transfer');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/admin/fees')
            ->assertRedirect('/admin/fees/provider');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/admin/fees/provider')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/ProviderFees')
                ->where('role', 'admin')
                ->where('initialKind', 'provider')
                ->has('providerTiers', 1)
                ->where('providerTiers.0.company_name', 'KBZPay')
                ->where('providerTiers.0.feature', 'cash_in')
                ->where('providerTiers.0.fee_amount', '0.0001')
            );

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/admin/fees/transfer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/TransferFees')
                ->where('role', 'admin')
                ->where('initialKind', 'transfer')
                ->has('transferTiers', 1)
                ->where('transferTiers.0.company_from_name', 'KBZPay')
                ->where('transferTiers.0.company_to_name', 'Wave Money')
            );
    }

    public function test_provider_fee_web_action_rejects_overlapping_active_range(): void
    {
        [, $token] = $this->adminWithToken();
        $company = Company::query()->create(['name' => 'AYA Pay', 'category' => 'Pay', 'is_active' => true]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/admin/fees/provider', $this->providerPayload($company->id))
            ->assertRedirect();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->from('/admin/fees/provider/create')
            ->post('/admin/fees/provider', [
                ...$this->providerPayload($company->id),
                'amount_from' => 500_000,
                'amount_to' => 2_000_000,
            ])
            ->assertRedirect('/admin/fees/provider/create')
            ->assertSessionHasErrors('amount_from');
    }

    public function test_transfer_fee_web_action_supports_point_zero_zero_zero_one_percent(): void
    {
        [, $token] = $this->adminWithToken();
        $from = Company::query()->create(['name' => 'CB Pay', 'category' => 'Pay', 'is_active' => true]);
        $to = Company::query()->create(['name' => 'AYA Bank', 'category' => 'Bank', 'is_active' => true]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/admin/fees/transfer', $this->transferPayload($from->id, $to->id))
            ->assertRedirect('/admin/fees/transfer');

        $fee = app(TransferFeeCalculator::class)->resolve($from->id, $to->id, 1_000_000);

        $this->assertSame('100.00', $fee['customer_fee']);
    }

    public function test_non_admin_cannot_use_admin_fee_web_actions(): void
    {
        $user = User::factory()->create([
            'role' => 'teller',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
        $token = app(NgweLweTokenService::class)->create($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/admin/fees')
            ->assertForbidden();
    }

    public function test_admin_cookie_remains_authenticated_during_fee_crud_when_legacy_header_is_stale(): void
    {
        [, $token] = $this->adminWithToken();
        $company = Company::query()->create([
            'name' => 'Cookie Pay',
            'category' => 'Pay',
            'is_active' => true,
        ]);

        $response = $this
            ->withUnencryptedCookie('ngwe_lwe_api_token', $token)
            ->withHeader('Authorization', 'Bearer stale-local-storage-token')
            ->post('/admin/fees/provider', $this->providerPayload($company->id));

        $response->assertRedirect('/admin/fees/provider');
        $this->assertDatabaseHas('commission_tiers', [
            'company_id' => $company->id,
            'feature' => 'cash_in',
        ]);

        $tierId = (int) CommissionTier::query()
            ->where('company_id', $company->id)
            ->valueOrFail('id');

        $this
            ->withUnencryptedCookie('ngwe_lwe_api_token', $token)
            ->withHeader('Authorization', 'Bearer stale-local-storage-token')
            ->get('/admin/fees/provider')
            ->assertOk();

        $this
            ->withUnencryptedCookie('ngwe_lwe_api_token', $token)
            ->withHeader('Authorization', 'Bearer stale-local-storage-token')
            ->put('/admin/fees/provider/'.$tierId, [
                ...$this->providerPayload($company->id),
                'fee_amount' => 0.0002,
            ])
            ->assertRedirect('/admin/fees/provider');

        $this->assertDatabaseHas('commission_tiers', [
            'id' => $tierId,
            'fee_amount' => 0.0002,
        ]);

        $this
            ->withUnencryptedCookie('ngwe_lwe_api_token', $token)
            ->withHeader('Authorization', 'Bearer stale-local-storage-token')
            ->delete('/admin/fees/provider/'.$tierId)
            ->assertRedirect('/admin/fees/provider');

        $this->assertDatabaseMissing('commission_tiers', ['id' => $tierId]);
    }

    private function adminWithToken(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        return [$admin, app(NgweLweTokenService::class)->create($admin)];
    }

    private function providerPayload(int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'feature' => 'cash_in',
            'amount_from' => 1,
            'amount_to' => 1_000_000,
            'fee_type' => 'PERCENTAGE',
            'fee_amount' => 0.0001,
            'additional_fee_type' => 'FIXED',
            'additional_fee_amount' => 0,
            'comm_type' => 'PERCENTAGE',
            'comm_amount' => 0.0001,
            'is_active' => true,
        ];
    }

    private function transferPayload(int $fromCompanyId, int $toCompanyId): array
    {
        return [
            'company_from_id' => $fromCompanyId,
            'company_to_id' => $toCompanyId,
            'amount_from' => 1,
            'amount_to' => 1_000_000,
            'fee_type' => 'PERCENTAGE',
            'fee_amount' => 0.0001,
            'additional_fee_type' => 'FIXED',
            'additional_fee_amount' => 0,
            'is_active' => true,
        ];
    }
}
