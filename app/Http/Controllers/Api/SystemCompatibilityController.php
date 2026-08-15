<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SystemCompatibilityController extends Controller
{
    public function wsTicket(Request $request): JsonResponse
    {
        $user = $request->user();
        $ticket = Str::random(48);

        Cache::put("ws-ticket:{$ticket}", [
            'user_id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
        ], now()->addMinute());

        return response()->json([
            'ticket' => $ticket,
            'expires_in' => 60,
            'user' => $this->safeUser($user),
            'ws_url' => $this->reverbUrl($ticket),
            'driver' => 'reverb',
        ]);
    }

    public function backup(Request $request, DatabaseBackupService $backups): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only');

        $path = $backups->create();

        return response()->json([
            'message' => 'Backup created.',
            'path' => $path,
        ], 201);
    }

    private function reverbUrl(string $ticket): string
    {
        $scheme = (string) config('reverb.apps.apps.0.options.scheme', 'http');
        $host = (string) config('reverb.apps.apps.0.options.host', config('app.url', 'localhost'));
        $port = (int) config('reverb.apps.apps.0.options.port', 8080);
        $key = (string) config('reverb.apps.apps.0.key', '');
        $protocol = $scheme === 'https' ? 'wss' : 'ws';
        $host = preg_replace('#^https?://#', '', $host) ?: 'localhost';
        $portPart = in_array($port, [80, 443], true) ? '' : ":{$port}";

        return "{$protocol}://{$host}{$portPart}/app/{$key}?protocol=7&client=js&version=compat&ticket={$ticket}";
    }

    /**
     * @return array<string, mixed>
     */
    private function safeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'role' => $user->role,
        ];
    }
}
