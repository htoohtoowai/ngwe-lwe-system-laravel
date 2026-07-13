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
                    ['role' => 'Owner', 'username' => 'owner', 'password' => 'password123', 'pin' => '1111'],
                    ['role' => 'Cashier', 'username' => 'cashier', 'password' => 'password123', 'pin' => '2222'],
                    ['role' => 'Employee', 'username' => 'employee', 'password' => 'password123', 'pin' => '3333'],
                ]
                : null,
        ]);
    }
}
