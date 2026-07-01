<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', function ($user, int $userId): bool {
    return (int) $user->id === $userId && (bool) $user->is_active;
});

Broadcast::channel('owner', function ($user): bool {
    return $user->role === 'owner' && (bool) $user->is_active;
});

Broadcast::channel('cashier', function ($user): bool {
    return $user->role === 'cashier' && (bool) $user->is_active;
});

Broadcast::channel('employee', function ($user): bool {
    return $user->role === 'employee' && (bool) $user->is_active;
});
