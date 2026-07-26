<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Login', [
            'returnTo' => $this->safeReturnTo($request->query('return')),
            'demoUsers' => app()->environment('local')
                ? [
                    ['role' => 'Admin', 'username' => 'admin', 'password' => 'password123', 'pin' => '1111'],
                    ['role' => 'Cashier', 'username' => 'cashier', 'password' => 'password123', 'pin' => '2222'],
                    ['role' => 'Teller', 'username' => 'teller', 'password' => 'password123', 'pin' => '3333'],
                ]
                : null,
        ]);
    }

    private function safeReturnTo(mixed $value): ?string
    {
        if (! is_string($value) || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        return str_starts_with($value, '/login') ? null : $value;
    }
}
