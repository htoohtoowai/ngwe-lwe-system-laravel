<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\PrivateChannel;

trait UsesNgweLweBroadcastChannels
{
    /**
     * @param  array<int, string>  $roles
     * @return array<int, PrivateChannel>
     */
    protected function roleChannels(array $roles): array
    {
        return array_map(
            fn (string $role): PrivateChannel => new PrivateChannel($role),
            $roles,
        );
    }

    protected function userChannel(int $userId): PrivateChannel
    {
        return new PrivateChannel('user.'.$userId);
    }
}
