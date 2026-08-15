<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VaultTransaction;
use App\Repositories\CashDenominationRepository;
use App\Services\CashFloatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VaultTransactionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('vault transaction audit tests');
        parent::setUp();
    }

    public function test_float_issue_creates_denomination_level_audit_entries(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $teller = User::factory()->create(['role' => 'teller']);
        app(CashDenominationRepository::class)->recordBulk('vault_in', [10000 => 10, 5000 => 10], $cashier->id);

        $float = app(CashFloatService::class)->issue($cashier, $teller->id, [10000 => 2, 5000 => 1]);

        $this->assertSame(2, VaultTransaction::query()
            ->where('float_id', $float->id)
            ->where('txn_type', 'float_issue')
            ->count());
    }
}
