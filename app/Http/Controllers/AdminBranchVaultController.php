<?php

namespace App\Http\Controllers;

use App\Models\BalanceAdjustmentRequest;
use App\Services\BalanceAdjustmentRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminBranchVaultController extends Controller
{
    public function __construct(
        private readonly BalanceAdjustmentRequestService $adjustments,
    ) {}

    /**
     * Cash vault changes are request-only. The branch Cashier applies the
     * actual movement later by confirming the request with their PIN.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'entry_type' => [
                'required',
                Rule::in(['vault_in', 'vault_out']),
            ],
            'denominations' => ['required', 'array', 'min:1'],
            'denominations.*' => ['integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->adjustments->create($request->user(), [
            'branch_id' => (int) $data['branch_id'],
            'target_type' => BalanceAdjustmentRequest::TARGET_CASH,
            'direction' => $data['entry_type'] === 'vault_in'
                ? BalanceAdjustmentRequest::DIRECTION_DEPOSIT
                : BalanceAdjustmentRequest::DIRECTION_WITHDRAW,
            'denominations' => $data['denominations'],
            'note' => $data['note'] ?? null,
        ]);

        return back()->with(
            'success',
            'Cash adjustment request sent to the branch Cashier for PIN confirmation.',
        );
    }
}
