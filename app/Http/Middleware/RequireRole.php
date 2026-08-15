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

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! in_array($user->role, $roles, true)) {
            return redirect($this->homeForRole($user->role))
                ->withErrors(['request' => 'Access denied']);
        }

        return $next($request);
    }

    private function homeForRole(string $role): string
    {
        return match ($role) {
            'admin' => '/admin',
            'cashier' => '/cashier',
            'teller' => '/dashboard',
            default => '/login',
        };
    }
}
