<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! in_array($user->role, $roles, true)) {
            if (! $request->is('api/*') && ! ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return redirect($this->homeForRole($user?->role))
                    ->withErrors(['request' => 'Access denied']);
            }

            return response()->json(['message' => 'Access denied'], 403);
        }

        return $next($request);
    }

    private function homeForRole(?string $role): string
    {
        return match ($role) {
            'admin' => '/admin',
            'cashier' => '/cashier',
            'teller' => '/dashboard',
            default => '/login',
        };
    }
}
