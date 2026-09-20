<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Branch;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminBranchUserController extends Controller
{
    public function __construct(private readonly UserRepository $users) {}

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data = $this->normalizeAndValidateAssignment($data);

        try {
            $user = $this->users->create($data);
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'username' => 'Username or email already exists.',
            ]);
        }

        return redirect()
            ->route('admin.operations.detail', ['section' => 'users', 'resourceId' => $user->id])
            ->with('success', 'User created.');
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if ($user->role === 'admin') {
            if (array_key_exists('role', $data) && $data['role'] !== 'admin') {
                throw ValidationException::withMessages([
                    'role' => 'The system Admin cannot be changed to another role.',
                ]);
            }

            if (($data['is_active'] ?? true) === false) {
                throw ValidationException::withMessages([
                    'is_active' => 'The system Admin must remain active.',
                ]);
            }
        }

        $data = $this->normalizeAndValidateAssignment($data, $user);

        try {
            $this->users->update($user, $data);
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'username' => 'Username or email already exists.',
            ]);
        }

        return redirect()
            ->route('admin.operations.detail', ['section' => 'users', 'resourceId' => $user->id])
            ->with('success', 'User updated.');
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        if ($user->role === 'admin' && $data['is_active'] === false) {
            throw ValidationException::withMessages([
                'is_active' => 'The system Admin must remain active.',
            ]);
        }

        $this->users->update($user, ['is_active' => $data['is_active']]);

        return back()->with('success', 'User status updated.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'new_password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $this->users->update($user, ['password' => $data['new_password']]);

        return back()->with('success', 'User password reset.');
    }

    public function setPin(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'digits_between:4,8'],
        ]);

        $this->users->update($user, ['pin' => $data['pin']]);

        return back()->with('success', 'User PIN updated.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeAndValidateAssignment(array $data, ?User $target = null): array
    {
        $role = (string) ($data['role'] ?? $target?->role ?? '');
        $branchId = array_key_exists('branch_id', $data)
            ? ($data['branch_id'] !== null ? (int) $data['branch_id'] : null)
            : $target?->branch_id;

        if ($role === 'admin') {
            $otherAdminExists = User::query()
                ->withoutGlobalScopes()
                ->where('role', 'admin')
                ->when($target !== null, fn ($query) => $query->whereKeyNot($target->id))
                ->exists();

            if ($otherAdminExists) {
                throw ValidationException::withMessages([
                    'role' => 'Only one Admin account is allowed.',
                ]);
            }

            $data['branch_id'] = null;

            return $data;
        }

        if ($branchId === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'Select a branch for Cashier and Teller accounts.',
            ]);
        }

        $branch = Branch::query()
            ->whereKey($branchId)
            ->where('is_active', true)
            ->first();

        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'Select an active branch.',
            ]);
        }

        if ($role === 'cashier') {
            $otherCashierExists = User::query()
                ->withoutGlobalScopes()
                ->where('role', 'cashier')
                ->where('branch_id', $branchId)
                ->when($target !== null, fn ($query) => $query->whereKeyNot($target->id))
                ->exists();

            if ($otherCashierExists) {
                throw ValidationException::withMessages([
                    'branch_id' => 'This branch already has a Cashier.',
                ]);
            }
        }

        $data['branch_id'] = $branchId;

        return $data;
    }
}
