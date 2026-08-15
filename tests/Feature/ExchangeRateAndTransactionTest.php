<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExchangeRate;
use App\Repositories\ExchangeRateRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateAndTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('exchange rate tests');
        parent::setUp();
    }

    public function test_provider_specific_exchange_rate_wins_over_global_fallback(): void
    {
        $company = Company::query()->create(['name' => 'KBZ Pay', 'category' => 'Pay', 'is_active' => true]);
        ExchangeRate::query()->create([
            'company_id' => null,
            'base_currency' => 'THB', 'quote_currency' => 'MMK', 'base_amount' => 1,
            'buy_rate' => 120, 'sell_rate' => 121, 'is_active' => true,
        ]);
        $provider = ExchangeRate::query()->create([
            'company_id' => $company->id,
            'base_currency' => 'THB', 'quote_currency' => 'MMK', 'base_amount' => 1,
            'buy_rate' => 125, 'sell_rate' => 126, 'is_active' => true,
        ]);

        $resolved = app(ExchangeRateRepository::class)->getLatestForCompany($company->id);
        $this->assertSame($provider->id, $resolved?->id);
    }

    public function test_global_exchange_rate_is_used_when_provider_specific_rate_is_missing(): void
    {
        $company = Company::query()->create(['name' => 'Wave Money', 'category' => 'Pay', 'is_active' => true]);
        $global = ExchangeRate::query()->create([
            'company_id' => null,
            'base_currency' => 'THB', 'quote_currency' => 'MMK', 'base_amount' => 1,
            'buy_rate' => 120, 'sell_rate' => 121, 'is_active' => true,
        ]);

        $this->assertSame($global->id, app(ExchangeRateRepository::class)->getLatestForCompany($company->id)?->id);
    }
}
