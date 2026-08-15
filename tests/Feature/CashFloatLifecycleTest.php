<?php

namespace Tests\Feature;

use App\Models\CashFloatAssignment;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Services\CashFloatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashFloatLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('cash float lifecycle tests');
        parent::setUp();
    }

    public function test_cashier_issue_teller_activate_return_and_cashier_confirm_closes_float(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        $teller = User::factory()->create(['role' => 'teller', 'pin_hash' => Hash::make('3333')]);
        $vault = app(CashDenominationRepository::class);
        $vault->recordBulk('vault_in', [10000 => 10], $cashier->id);

        $service = app(CashFloatService::class);
        $float = $service->issue($cashier, $teller->id, [10000 => 5]);
        $this->assertSame('PENDING_RECEIPT', $float->status);

        $service->activate($teller, $float->fresh(), '3333', [10000 => 5]);
        $this->assertSame('ACTIVE', $float->fresh()->status);

        $service->initiateReturn($teller, $float->fresh(), [10000 => 5], '3333');
        $this->assertSame('PENDING_RECONCILIATION', $float->fresh()->status);

        $service->confirmReturn($cashier, $float->fresh(), 50000, '2222');
        $this->assertSame('CLOSED', CashFloatAssignment::query()->findOrFail($float->id)->status);
        $this->assertSame(10, $vault->getVaultBalance()[10000]);
    }
}
