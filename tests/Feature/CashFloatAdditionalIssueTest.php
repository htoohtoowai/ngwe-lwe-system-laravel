<?php

namespace Tests\Feature;

use App\Models\CashFloatIssue;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Services\CashFloatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class CashFloatAdditionalIssueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('cash float additional issue tests');
        parent::setUp();
    }

    public function test_active_float_can_receive_multiple_additional_issues_during_same_session(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        $teller = User::factory()->create(['role' => 'teller', 'pin_hash' => Hash::make('3333')]);
        $vault = app(CashDenominationRepository::class);
        $floatRepository = app(CashFloatRepository::class);
        $service = app(CashFloatService::class);

        $vault->recordBulk('vault_in', [10000 => 20, 5000 => 20], $cashier->id);

        $float = $service->issue($cashier, $teller->id, [10000 => 5]);
        $service->activate($teller, $float->fresh(), '3333', [10000 => 5]);

        $this->assertSame('50000.00', $float->fresh()->current_balance);
        $this->assertSame('RECEIVED', CashFloatIssue::query()->where('issue_type', 'INITIAL')->firstOrFail()->status);

        $service->issue($cashier, $teller->id, [10000 => 2, 5000 => 2], 'Lunch replenishment');
        $firstAdditional = CashFloatIssue::query()
            ->where('float_id', $float->id)
            ->where('issue_type', 'ADDITIONAL')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('PENDING_RECEIPT', $firstAdditional->status);
        $this->assertSame('50000.00', $float->fresh()->current_balance, 'Pending issue must not increase Teller balance.');

        $service->receiveAdditionalIssue(
            $teller,
            $firstAdditional,
            '3333',
            [10000 => 2, 5000 => 2],
        );

        $received = $float->fresh();
        $this->assertSame('80000.00', $received->current_balance);
        $this->assertSame('80000.00', $received->total_amount);
        $this->assertSame('RECEIVED', $firstAdditional->fresh()->status);
        $this->assertSame([10000 => 7, 5000 => 2], $floatRepository->getDenominationBalance($float->id));

        $service->issue($cashier, $teller->id, [10000 => 1], 'Second replenishment');
        $secondAdditional = CashFloatIssue::query()
            ->where('float_id', $float->id)
            ->where('issue_type', 'ADDITIONAL')
            ->where('status', 'PENDING_RECEIPT')
            ->latest('id')
            ->firstOrFail();

        $service->rejectAdditionalIssue($teller, $secondAdditional, '3333', 'Count did not match.');

        $this->assertSame('REJECTED', $secondAdditional->fresh()->status);
        $this->assertSame('80000.00', $float->fresh()->current_balance);
        $this->assertSame([10000 => 7, 5000 => 2], $floatRepository->getDenominationBalance($float->id));
        $this->assertSame(13, $vault->getVaultBalance()[10000]);
        $this->assertSame(18, $vault->getVaultBalance()[5000]);
    }

    public function test_float_cannot_be_returned_while_additional_issue_is_pending(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        $teller = User::factory()->create(['role' => 'teller', 'pin_hash' => Hash::make('3333')]);
        $vault = app(CashDenominationRepository::class);
        $service = app(CashFloatService::class);

        $vault->recordBulk('vault_in', [10000 => 10], $cashier->id);

        $float = $service->issue($cashier, $teller->id, [10000 => 5]);
        $service->activate($teller, $float->fresh(), '3333', [10000 => 5]);
        $service->issue($cashier, $teller->id, [10000 => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Receive or reject all pending additional float issues');

        $service->initiateReturn($teller, $float->fresh(), [10000 => 5], '3333');
    }
}
