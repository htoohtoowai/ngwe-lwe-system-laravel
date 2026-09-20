<?php

namespace Tests\Feature;

use App\Events\BalanceUpdated;
use App\Events\CashInPending;
use App\Events\FloatStatusChanged;
use App\Events\NewTransaction;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchRealtimeIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('branch realtime isolation tests');
        parent::setUp();
    }

    public function test_new_transaction_goes_to_admin_branch_cashier_and_creator_only(): void
    {
        $branch = $this->branch('BR-002', 'Branch 2');
        $teller = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branch->id,
        ]);

        $event = new NewTransaction([
            'id' => 10,
            'created_by' => $teller->id,
            'branch_id' => $branch->id,
        ], $branch->id);

        $names = $this->channelNames($event->broadcastOn());

        $this->assertContains('admin', $names);
        $this->assertContains("branch.{$branch->id}.cashier", $names);
        $this->assertContains("user.{$teller->id}", $names);
        $this->assertNotContains("branch.{$branch->id}.teller", $names);
        $this->assertNotContains('cashier', $names);
        $this->assertNotContains('teller', $names);
    }

    public function test_pending_cash_event_is_sent_only_to_its_branch_cashier(): void
    {
        $branch = $this->branch('BR-002', 'Branch 2');

        $event = new CashInPending([
            'id' => 11,
            'branch_id' => $branch->id,
        ], $branch->id);

        $this->assertSame(
            ["branch.{$branch->id}.cashier"],
            $this->channelNames($event->broadcastOn()),
        );
    }

    public function test_balance_update_is_scoped_to_branch_staff_plus_admin(): void
    {
        $branch = $this->branch('BR-002', 'Branch 2');

        $event = new BalanceUpdated([], $branch->id);
        $names = $this->channelNames($event->broadcastOn());

        $this->assertContains('admin', $names);
        $this->assertContains("branch.{$branch->id}.cashier", $names);
        $this->assertContains("branch.{$branch->id}.teller", $names);
        $this->assertNotContains('cashier', $names);
        $this->assertNotContains('teller', $names);
    }

    public function test_float_status_is_sent_to_admin_branch_cashier_and_owner_user(): void
    {
        $branch = $this->branch('BR-002', 'Branch 2');
        $teller = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branch->id,
        ]);

        $event = new FloatStatusChanged(
            ['id' => 20, 'branch_id' => $branch->id],
            $teller->id,
            $branch->id,
        );

        $names = $this->channelNames($event->broadcastOn());

        $this->assertContains('admin', $names);
        $this->assertContains("branch.{$branch->id}.cashier", $names);
        $this->assertContains("user.{$teller->id}", $names);
        $this->assertNotContains('cashier', $names);
    }

    /**
     * @param  array<int, Channel>  $channels
     * @return array<int, string>
     */
    private function channelNames(array $channels): array
    {
        return array_map(
            function (Channel $channel): string {
                return preg_replace('/^private-/', '', $channel->name) ?? $channel->name;
            },
            $channels,
        );
    }

    private function branch(string $code, string $name): Branch
    {
        return Branch::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }
}
