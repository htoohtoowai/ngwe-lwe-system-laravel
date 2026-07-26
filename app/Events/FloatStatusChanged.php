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
    ) {}

    public function broadcastOn(): array
    {
        return [
            ...$this->roleChannels(['admin', 'cashier']),
            $this->userChannel($this->employeeId),
        ];
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
