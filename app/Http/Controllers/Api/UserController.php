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

class UserController extends Controller
{
    public function __construct(private readonly UserRepository $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->all($request->boolean('include_inactive')));
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
            return response()->json(['message' => 'Owners cannot deactivate their own active session.'], 422);
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
            return response()->json(['message' => 'Owners cannot deactivate their own active session.'], 422);
        }

        return new UserResource($this->users->deactivate($user));
    }
}
