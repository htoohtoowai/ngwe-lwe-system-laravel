<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentCommissionTier;
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
    }
}
