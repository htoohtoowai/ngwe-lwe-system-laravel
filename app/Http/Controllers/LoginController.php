<?php

namespace App\Http\Controllers;

use App\Repositories\UserRepository;
use App\Services\NgweLweTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Cookie;

class LoginController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly NgweLweTokenService $tokens,
    ) {}

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

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->users->findActiveByUsername($credentials['username']);
        $hash = $user?->password ?? (string) config('ngwe_lwe.auth.dummy_password_hash');

        if (! Hash::check($credentials['password'], $hash) || $user === null) {
            throw ValidationException::withMessages([
                'username' => 'Invalid credentials',
            ]);
        }

        $token = $this->tokens->create($user);
        $destination = $this->safeReturnTo($request->input('return_to'))
            ?? $this->consolePath($user->role);

        return redirect($destination)->withCookie($this->authCookie($token));
    }

    public function logout(): RedirectResponse
    {
        return redirect()->route('login')->withoutCookie('ngwe_lwe_api_token');
    }

    public function home(Request $request): RedirectResponse
    {
        return redirect($this->consolePath((string) $request->user()?->role));
    }

    private function safeReturnTo(mixed $value): ?string
    {
        if (! is_string($value) || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        return str_starts_with($value, '/login') ? null : $value;
    }

    private function consolePath(string $role): string
    {
        return match ($role) {
            'admin' => '/admin',
            'cashier' => '/cashier',
            default => '/dashboard',
        };
    }

    private function authCookie(string $token): Cookie
    {
        return cookie(
            name: 'ngwe_lwe_api_token',
            value: $token,
            minutes: (int) ceil((int) config('ngwe_lwe.auth.token_ttl_seconds', 86400) / 60),
            path: '/',
            domain: null,
            secure: str_starts_with((string) config('app.url'), 'https://'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }
}
