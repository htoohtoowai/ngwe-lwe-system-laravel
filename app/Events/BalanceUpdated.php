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
    public function __construct(
        public readonly array $accounts,
        public readonly ?int $branchId = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = $this->roleChannels(['admin']);

        if ($this->branchId !== null) {
            $channels[] = $this->branchRoleChannel($this->branchId, 'cashier');
            $channels[] = $this->branchRoleChannel($this->branchId, 'teller');
        }

        return $channels;
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
