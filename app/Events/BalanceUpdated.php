<?php

namespace App\Events;

use App\Events\Concerns\UsesNgweLweBroadcastChannels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BalanceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels, UsesNgweLweBroadcastChannels;

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    public function __construct(public readonly array $accounts) {}

    public function broadcastOn(): array
    {
        return $this->roleChannels(['owner', 'cashier', 'employee']);
    }

    public function broadcastAs(): string
    {
        return 'balance_update';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'balance_update',
            'accounts' => $this->accounts,
        ];
    }
}
