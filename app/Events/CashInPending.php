<?php

namespace App\Events;

use App\Events\Concerns\UsesNgweLweBroadcastChannels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CashInPending implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels, UsesNgweLweBroadcastChannels;

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function __construct(public readonly array $transaction) {}

    public function broadcastOn(): array
    {
        return $this->roleChannels(['cashier']);
    }

    public function broadcastAs(): string
    {
        return 'cash_in_pending';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'cash_in_pending',
            'transaction' => $this->transaction,
        ];
    }
}
