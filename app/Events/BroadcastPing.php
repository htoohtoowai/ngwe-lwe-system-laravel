<?php

namespace App\Events;

use App\Events\Concerns\UsesNgweLweBroadcastChannels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BroadcastPing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels, UsesNgweLweBroadcastChannels;

    public function __construct(
        public readonly int $userId,
        public readonly string $sentAt,
    ) {}

    public function broadcastOn(): array
    {
        return $this->roleChannels(['admin']);
    }

    public function broadcastAs(): string
    {
        return 'ping';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'ping',
            'user_id' => $this->userId,
            'sent_at' => $this->sentAt,
        ];
    }
}
