<?php

namespace App\Events;

use App\Events\Concerns\UsesNgweLweBroadcastChannels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FloatStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels, UsesNgweLweBroadcastChannels;

    /**
     * @param  array<string, mixed>  $cashFloat
     */
    public function __construct(
        public readonly array $cashFloat,
        public readonly int $employeeId,
        public readonly ?int $branchId = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            ...$this->roleChannels(['admin']),
            $this->userChannel($this->employeeId),
        ];

        if ($this->branchId !== null) {
            $channels[] = $this->branchRoleChannel($this->branchId, 'cashier');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'float_status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'float_status_changed',
            'float' => $this->cashFloat,
        ];
    }
}
