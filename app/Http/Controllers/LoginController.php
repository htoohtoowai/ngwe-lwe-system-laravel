<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Login', [
            'demoUsers' => app()->environment('local')
                ? [
                    ['role' => 'Admin', 'username' => 'admin', 'password' => 'password123', 'pin' => '1111'],
                    ['role' => 'Cashier', 'username' => 'cashier', 'password' => 'password123', 'pin' => '2222'],
                    ['role' => 'Teller', 'username' => 'teller', 'password' => 'password123', 'pin' => '3333'],
                ]
                : null,
        ]);
    }
}
