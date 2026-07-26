<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\SetPinRequest;
use App\Repositories\UserRepository;
use App\Services\NgweLweTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly NgweLweTokenService $tokens,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->users->findActiveByUsername($credentials['username']);
        $hash = $user?->password ?? (string) config('ngwe_lwe.auth.dummy_password_hash');

        if (! Hash::check($credentials['password'], $hash) || $user === null) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return response()->json([
            'token' => $this->tokens->create($user),
            'user' => $this->safeUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->safeUser($request->user())]);
    }

    public function logout(): JsonResponse
    {
        return response()->json(['message' => 'Logged out']);
    }

    public function setPin(SetPinRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->pin_hash = Hash::make($request->validated()['pin']);
        $user->save();

        return response()->json(['message' => 'PIN updated']);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated()['current_password'], (string) $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $this->users->update($user, ['password' => $request->validated()['password']]);

        return response()->json(['message' => 'Password updated. Please sign in again.']);
    }

    private function safeUser($user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
