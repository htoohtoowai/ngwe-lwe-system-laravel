<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentCommissionTier;
use App\Models\Company;
use App\Models\ProviderFeeTier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('database seeder tests');
        parent::setUp();
    }

    public function test_demo_seeder_uses_final_account_and_agent_commission_design(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Account::query()->where('account_type', 'BANK')->where('is_agent', true)->doesntExist());
        $this->assertTrue(Account::query()->where('account_identifier', '09256149970')->where('is_agent', true)->exists());
        $this->assertTrue(Account::query()->where('account_identifier', '09256149971')->where('is_agent', true)->exists());

        $tier = AgentCommissionTier::query()->first();
        $this->assertNotNull($tier);
        $this->assertArrayNotHasKey('feature', $tier->getAttributes());
        $this->assertArrayHasKey('out_commission_value', $tier->getAttributes());
        $this->assertArrayHasKey('in_commission_value', $tier->getAttributes());

        $wave = Company::query()->where('name', 'Wave Money')->firstOrFail();
        $kbz = Company::query()->where('name', 'KBZPay')->firstOrFail();

        $this->assertSame(14, ProviderFeeTier::query()->where('company_id', $wave->id)->where('feature', 'send_money')->count());
        $this->assertSame(0, ProviderFeeTier::query()->where('company_id', $wave->id)->where('feature', 'receive_money')->count());
        $this->assertSame(14, ProviderFeeTier::query()->where('company_id', $kbz->id)->where('feature', 'send_money')->count());
        $this->assertSame(0, ProviderFeeTier::query()->where('company_id', $kbz->id)->where('feature', 'receive_money')->count());

        $wave100k = AgentCommissionTier::query()
            ->where('company_id', $wave->id)
            ->where('amount_from', '50001.00')
            ->where('amount_to', '100000.00')
            ->firstOrFail();
        $this->assertSame('196.0000', $wave100k->out_commission_value);
        $this->assertSame('392.0000', $wave100k->in_commission_value);

        $kbz100k = AgentCommissionTier::query()
            ->where('company_id', $kbz->id)
            ->where('amount_from', '50001.00')
            ->where('amount_to', '100000.00')
            ->firstOrFail();
        $this->assertSame('300.0000', $kbz100k->out_commission_value);
        $this->assertSame('0.0000', $kbz100k->in_commission_value);
    }
}
