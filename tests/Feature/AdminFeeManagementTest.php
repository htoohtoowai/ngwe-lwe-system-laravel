<?php

namespace Tests\Feature;

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
            ->assertRedirect('/admin/fees?kind=provider');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/admin/fees/transfer', $this->transferPayload($from->id, $to->id))
            ->assertRedirect('/admin/fees?kind=transfer');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/admin/fees')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Fees')
                ->where('role', 'admin')
                ->has('providerTiers', 1)
                ->where('providerTiers.0.company_name', 'KBZPay')
                ->where('providerTiers.0.feature', 'cash_in')
                ->where('providerTiers.0.fee_amount', '0.0001')
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
            ->assertRedirect('/admin/fees?kind=transfer');

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
