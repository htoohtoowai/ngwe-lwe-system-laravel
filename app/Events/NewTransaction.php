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
    public function __construct(
        public readonly array $transaction,
        public readonly ?int $branchId = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = $this->roleChannels(['admin']);

        if ($this->branchId !== null) {
            $channels[] = $this->branchRoleChannel($this->branchId, 'cashier');
        }

        $creatorId = (int) ($this->transaction['created_by'] ?? 0);
        if ($creatorId > 0) {
            $channels[] = $this->userChannel($creatorId);
        }

        return $channels;
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
