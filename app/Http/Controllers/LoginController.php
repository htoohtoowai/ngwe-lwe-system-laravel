<?php

namespace App\Http\Controllers;

use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogService $audit,
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
            $this->audit->record(
                action: 'login_failed',
                category: 'authentication',
                module: 'authentication',
                entityType: 'user',
                entityId: $user?->id,
                description: 'Failed login attempt',
                details: ['username' => $credentials['username']],
                status: 'failed',
                failureReason: 'Invalid credentials',
                actor: $user,
                userId: $user?->id,
                actorName: $user?->full_name ?? $credentials['username'],
                actorRole: $user?->role,
            );

            throw ValidationException::withMessages([
                'username' => 'Invalid credentials',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('audit_login_at', now()->timestamp);

        $this->audit->record(
            action: 'login',
            category: 'authentication',
            module: 'authentication',
            entityType: 'user',
            entityId: $user->id,
            description: 'User signed in',
            details: ['username' => $user->username],
            actor: $user,
        );

        $destination = $this->safeReturnTo($request->input('return_to'))
            ?? $this->consolePath($user->role);

        return redirect($destination);
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = $request->user();
        $loginAt = $request->session()->get('audit_login_at');
        $duration = is_numeric($loginAt) ? max(0, now()->timestamp - (int) $loginAt) : null;

        if ($user !== null) {
            $this->audit->record(
                action: 'logout',
                category: 'authentication',
                module: 'authentication',
                entityType: 'user',
                entityId: $user->id,
                description: 'User signed out',
                details: $duration !== null ? ['session_duration_seconds' => $duration] : null,
                actor: $user,
            );
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
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
}
