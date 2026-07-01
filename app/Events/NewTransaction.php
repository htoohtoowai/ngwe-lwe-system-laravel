<?php

namespace App\Events;

use App\Events\Concerns\UsesNgweLweBroadcastChannels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewTransaction implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels, UsesNgweLweBroadcastChannels;

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function __construct(public readonly array $transaction) {}

    public function broadcastOn(): array
    {
        return $this->roleChannels(['owner', 'cashier', 'employee']);
    }

    public function broadcastAs(): string
    {
        return 'new_transaction';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'new_transaction',
            'transaction' => $this->transaction,
        ];
    }
}
