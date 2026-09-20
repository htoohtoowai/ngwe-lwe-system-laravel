<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{userId}', function ($user, int $userId): bool {
    return (int) $user->id === $userId && (bool) $user->is_active;
});

Broadcast::channel('admin', function ($user): bool {
    return $user->role === 'admin' && (bool) $user->is_active;
});

// Kept for backward-compatible channel authentication only. Operational
// branch events are no longer broadcast to these global staff channels.
Broadcast::channel('cashier', function ($user): bool {
    return $user->role === 'cashier' && (bool) $user->is_active;
});

Broadcast::channel('teller', function ($user): bool {
    return $user->role === 'teller' && (bool) $user->is_active;
});

Broadcast::channel(
    'branch.{branchId}.cashier',
    function ($user, int $branchId): bool {
        return $user->role === 'cashier'
            && (bool) $user->is_active
            && (int) $user->branch_id === $branchId;
    }
);

Broadcast::channel(
    'branch.{branchId}.teller',
    function ($user, int $branchId): bool {
        return $user->role === 'teller'
            && (bool) $user->is_active
            && (int) $user->branch_id === $branchId;
    }
);
