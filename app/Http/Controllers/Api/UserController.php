<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(private readonly UserRepository $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->all($request->boolean('include_inactive')));
    }

    public function employees(Request $request): AnonymousResourceCollection
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'cashier'], true), 403, 'Access denied');

        return UserResource::collection($this->users->activeByRole('teller'));
    }

    /** Compatibility endpoint for the reference application's user settings. */
    public function changePasswordCompat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'old_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'max:255'],
        ]);
        $user = $request->user();

        if (! Hash::check($data['old_password'], (string) $user->password)) {
            return response()->json(['message' => 'Old password incorrect'], 400);
        }

        $this->users->update($user, ['password' => $data['new_password']]);

        return response()->json(['message' => 'Password changed']);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only');

        $data = $request->validate([
            'new_password' => ['required', 'string', 'min:8', 'max:255'],
        ]);
        $this->users->update($user, ['password' => $data['new_password']]);

        return response()->json(['message' => 'Password reset', 'user_id' => $user->id]);
    }

    public function toggleActive(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only');

        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        if ($request->user()->is($user) && $data['is_active'] === false) {
            return response()->json(['message' => 'Admins cannot deactivate their own active session.'], 422);
        }

        $this->users->update($user, ['is_active' => $data['is_active']]);

        return response()->json(['message' => 'Updated', 'user_id' => $user->id]);
    }

    public function setUserPin(Request $request, User $user): JsonResponse
    {
        abort_unless(
            $request->user()?->role === 'admin' || $request->user()?->is($user),
            403,
            'Access denied',
        );

        $data = $request->validate(['pin' => ['required', 'digits_between:4,8']]);
        $this->users->update($user, ['pin' => $data['pin']]);

        return response()->json(['message' => 'PIN set']);
    }

    public function changePin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_pin' => ['required', 'digits_between:4,8'],
            'new_pin' => ['required', 'digits_between:4,8'],
        ]);
        $user = $request->user();

        if (! $user->pin_hash || ! Hash::check($data['current_pin'], (string) $user->pin_hash)) {
            return response()->json(['message' => 'Incorrect current PIN.'], 401);
        }

        $this->users->update($user, ['pin' => $data['new_pin']]);

        return response()->json(['message' => 'PIN changed']);
    }

    public function store(UserRequest $request): JsonResponse
    {
        try {
            $user = $this->users->create($request->validated());
        } catch (QueryException) {
            return response()->json(['message' => 'Username or email already exists.'], 409);
        }

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(UserRequest $request, User $user): UserResource|JsonResponse
    {
        $data = $request->validated();

        if ($data === []) {
            return response()->json(['message' => 'No fields to update.'], 400);
        }

        if (
            $request->user()->is($user)
            && array_key_exists('is_active', $data)
            && $data['is_active'] === false
        ) {
            return response()->json(['message' => 'Admins cannot deactivate their own active session.'], 422);
        }

        try {
            $user = $this->users->update($user, $data);
        } catch (QueryException) {
            return response()->json(['message' => 'Username or email already exists.'], 409);
        }

        return new UserResource($user);
    }

    public function destroy(Request $request, User $user): UserResource|JsonResponse
    {
        if ($request->user()->is($user)) {
            return response()->json(['message' => 'Admins cannot deactivate their own active session.'], 422);
        }

        return new UserResource($this->users->deactivate($user));
    }
}
