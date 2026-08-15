<?php

namespace Tests\Feature;

use App\Events\BalanceUpdated;
use App\Events\CashInPending;
use App\Events\NewTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RealtimeBroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReverbBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('realtime broadcast tests');
        parent::setUp();
    }

    public function test_pending_cash_in_dispatches_transaction_cashier_and_balance_events(): void
    {
        $teller = User::factory()->create(['role' => 'teller']);
        [$account] = $this->createCompanyAccountFixture(100000);
        $transaction = Transaction::query()->create([
            'transaction_type' => 'cash_in',
            'account_id' => $account->id,
            'amount' => 10000,
            'customer_fee' => 0,
            'additional_fee_amount' => 0,
            'balance_change' => -10000,
            'currency' => 'MMK',
            'fee_payment_method' => 'cash',
            'created_by' => $teller->id,
            'status' => 'PENDING_CASHIER_CONFIRM',
            'received_denominations' => ['10000' => 1],
            'handoff_denominations' => ['10000' => 1],
        ]);

        Event::fake([NewTransaction::class, CashInPending::class, BalanceUpdated::class]);
        app(RealtimeBroadcastService::class)->transactionCreated($transaction);

        Event::assertDispatched(NewTransaction::class);
        Event::assertDispatched(CashInPending::class);
        Event::assertDispatched(BalanceUpdated::class);
    }
}
