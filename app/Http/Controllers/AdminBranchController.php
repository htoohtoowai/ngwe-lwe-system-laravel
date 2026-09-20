<?php

namespace App\Http\Controllers;

use App\Http\Requests\BranchRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminBranchController extends Controller
{
    public function store(BranchRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $branch = DB::transaction(function () use ($data): Branch {
            $branch = Branch::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->seedVaultRows($branch);

            return $branch;
        });

        return redirect()
            ->route('admin.branches.show', ['branch' => $branch->id])
            ->with('success', 'Branch created.');
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $data = $request->validated();

        if ($branch->code === Branch::MAIN_CODE && $data['code'] !== Branch::MAIN_CODE) {
            throw ValidationException::withMessages([
                'code' => 'The Main Branch code cannot be changed.',
            ]);
        }

        $nextActive = (bool) ($data['is_active'] ?? $branch->is_active);
        if (! $nextActive) {
            $this->guardCanDeactivate($branch);
        }

        $branch->fill([
            'code' => $branch->code === Branch::MAIN_CODE ? Branch::MAIN_CODE : $data['code'],
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_active' => $nextActive,
        ]);
        $branch->save();

        return redirect()
            ->route('admin.branches.show', ['branch' => $branch->id])
            ->with('success', 'Branch updated.');
    }

    public function toggle(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        if ($data['is_active'] === false) {
            $this->guardCanDeactivate($branch);
        }

        $branch->is_active = $data['is_active'];
        $branch->save();

        return back()->with('success', 'Branch status updated.');
    }

    private function guardCanDeactivate(Branch $branch): void
    {
        if ($branch->code === Branch::MAIN_CODE) {
            throw ValidationException::withMessages([
                'is_active' => 'Main Branch must remain active.',
            ]);
        }

        $activeStaffExists = User::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->exists();

        if ($activeStaffExists) {
            throw ValidationException::withMessages([
                'is_active' => 'Move or deactivate active Cashier/Teller accounts before deactivating this branch.',
            ]);
        }
    }

    private function seedVaultRows(Branch $branch): void
    {
        $denominations = DB::table('note_denominations')
            ->orderBy('id')
            ->pluck('id');

        foreach ($denominations as $denomination) {
            DB::table('branch_vault_denomination_balances')->insertOrIgnore([
                'branch_id' => $branch->id,
                'denomination_id' => (int) $denomination,
                'quantity' => 0,
                'total_value' => 0,
                'last_updated' => now(),
            ]);
        }
    }
}
