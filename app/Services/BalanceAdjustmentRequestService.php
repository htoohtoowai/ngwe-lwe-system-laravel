<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Branch;
use App\Models\User;
use App\Models\VaultTransaction;
use App\Repositories\CashDenominationRepository;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BalanceAdjustmentRequestService
{
    public function __construct(
        private readonly CashDenominationRepository $vault,
        private readonly PinVerifier $pinVerifier,
        private readonly RealtimeBroadcastService $broadcasts,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $requester, array $data): BalanceAdjustmentRequest
    {
        if (! in_array($requester->role, ['admin', 'cashier'], true)) {
            throw ValidationException::withMessages([
                'form' => 'Only Admin or Cashier can create balance requests.',
            ]);
        }

        $branch = Branch::query()->findOrFail((int) $data['branch_id']);

        if (! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Select an active branch.',
            ]);
        }

        if (
            $requester->role === 'cashier'
            && (int) $requester->branch_id !== (int) $branch->id
        ) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cashier can request changes only for their own branch.',
            ]);
        }

        $cashier = User::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->where('role', 'cashier')
            ->where('is_active', true)
            ->first();

        if ($cashier === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'The selected branch has no active Cashier.',
            ]);
        }

        if (
            $requester->role === 'cashier'
            && (int) $cashier->id !== (int) $requester->id
        ) {
            throw ValidationException::withMessages([
                'form' => 'Only the responsible branch Cashier can create this request.',
            ]);
        }

        $approver = $requester->role === 'admin'
            ? $cashier
            : User::query()
                ->withoutGlobalScopes()
                ->where('role', 'admin')
                ->where('is_active', true)
                ->first();

        if ($approver === null) {
            throw ValidationException::withMessages([
                'form' => 'No active approver is available.',
            ]);
        }

        $targetType = (string) $data['target_type'];
        $direction = (string) $data['direction'];
        $note = trim((string) ($data['note'] ?? ''));

        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => 'Remark is required.',
            ]);
        }

        if (! in_array($targetType, [
            BalanceAdjustmentRequest::TARGET_CASH,
            BalanceAdjustmentRequest::TARGET_ACCOUNT,
        ], true)) {
            throw ValidationException::withMessages([
                'target_type' => 'Invalid adjustment target.',
            ]);
        }

        if (! in_array($direction, [
            BalanceAdjustmentRequest::DIRECTION_DEPOSIT,
            BalanceAdjustmentRequest::DIRECTION_WITHDRAW,
        ], true)) {
            throw ValidationException::withMessages([
                'direction' => 'Invalid adjustment direction.',
            ]);
        }

        $accountId = null;
        $denominations = null;
        $amount = '0.00';

        if ($targetType === BalanceAdjustmentRequest::TARGET_ACCOUNT) {
            $account = Account::query()
                ->withoutGlobalScopes()
                ->with('company')
                ->findOrFail((int) ($data['account_id'] ?? 0));

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'account_id' => 'The selected account is inactive.',
                ]);
            }

            if (! in_array($account->account_type, [AccountType::Bank, AccountType::Pay], true)) {
                throw ValidationException::withMessages([
                    'account_id' => 'Only Bank and Pay accounts can use this workflow.',
                ]);
            }

            if (
                $account->branch_id !== null
                && (int) $account->branch_id !== (int) $branch->id
            ) {
                throw ValidationException::withMessages([
                    'branch_id' => 'This account belongs to another branch.',
                ]);
            }

            $rawAmount = Money::normalize($data['amount'] ?? 0);

            if ((float) $rawAmount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount must be greater than zero.',
                ]);
            }

            if (
                $direction === BalanceAdjustmentRequest::DIRECTION_WITHDRAW
                && (float) Money::normalize($account->balance) < (float) $rawAmount
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'Requested withdrawal exceeds the current account balance.',
                ]);
            }

            $accountId = (int) $account->id;
            $amount = $rawAmount;
        } else {
            $denominations = $this->normalizeDenominations(
                $data['denominations'] ?? [],
            );
            $total = Money::denominationTotal($denominations);

            if ($total <= 0) {
                throw ValidationException::withMessages([
                    'denominations' => 'Enter at least one banknote.',
                ]);
            }

            if ($direction === BalanceAdjustmentRequest::DIRECTION_WITHDRAW) {
                $available = $this->vault->getAvailableBalance(
                    branchId: (int) $branch->id,
                );

                foreach ($denominations as $denomination => $quantity) {
                    if ($quantity > (int) ($available[$denomination] ?? 0)) {
                        throw ValidationException::withMessages([
                            'denominations' => "Not enough {$denomination} MMK notes in the selected branch vault.",
                        ]);
                    }
                }
            }

            $amount = Money::normalize($total);
        }

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => (int) $branch->id,
                'target_type' => $targetType,
                'account_id' => $accountId,
                'direction' => $direction,
                'amount' => $amount,
                'denominations_json' => $denominations,
                'note' => $note,
                'status' => BalanceAdjustmentRequest::STATUS_PENDING,
                'requested_by' => $requester->id,
                'assigned_cashier_id' => $cashier->id,
                'approver_id' => $approver->id,
            ]);

        ActivityLog::query()->create([
            'user_id' => $requester->id,
            'action' => 'adjustment_request_created',
            'entity_type' => 'balance_adjustment_request',
            'entity_id' => $adjustment->id,
            'details' => [
                'branch_id' => (int) $branch->id,
                'target_type' => $targetType,
                'account_id' => $accountId,
                'direction' => $direction,
                'amount' => $amount,
                'assigned_cashier_id' => $cashier->id,
                'approver_id' => $approver->id,
            ],
        ]);

        return $this->loadRelations($adjustment);
    }

    public function confirm(
        User $cashier,
        BalanceAdjustmentRequest $adjustment,
        string $pin,
    ): BalanceAdjustmentRequest {
        $this->pinVerifier->verify($cashier, $pin);

        $status = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->whereKey($adjustment->id)
            ->value('status');

        if ($status === BalanceAdjustmentRequest::STATUS_APPROVED) {
            return $this->completeApprovedCash($cashier, $adjustment);
        }

        return $this->approve($cashier, $adjustment);
    }

    public function approveByAdmin(
        User $admin,
        BalanceAdjustmentRequest $adjustment,
    ): BalanceAdjustmentRequest {
        if ($admin->role !== 'admin' || ! $admin->is_active) {
            throw ValidationException::withMessages([
                'request' => 'Admin approval is required.',
            ]);
        }

        return $this->approve($admin, $adjustment);
    }

    public function reject(
        User $cashier,
        BalanceAdjustmentRequest $adjustment,
        string $pin,
        ?string $note = null,
    ): BalanceAdjustmentRequest {
        $this->pinVerifier->verify($cashier, $pin);

        return $this->rejectDecision($cashier, $adjustment, $note);
    }

    public function rejectByAdmin(
        User $admin,
        BalanceAdjustmentRequest $adjustment,
        ?string $note = null,
    ): BalanceAdjustmentRequest {
        if ($admin->role !== 'admin' || ! $admin->is_active) {
            throw ValidationException::withMessages([
                'request' => 'Admin approval is required.',
            ]);
        }

        return $this->rejectDecision($admin, $adjustment, $note);
    }

    private function approve(
        User $approver,
        BalanceAdjustmentRequest $adjustment,
    ): BalanceAdjustmentRequest {
        $result = DB::transaction(function () use (
            $approver,
            $adjustment,
        ): array {
            $locked = BalanceAdjustmentRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardApprover($approver, $locked);

            if ($locked->status !== BalanceAdjustmentRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'request' => 'This request is no longer pending.',
                ]);
            }

            $requester = User::query()
                ->withoutGlobalScopes()
                ->findOrFail((int) $locked->requested_by);

            $locked->approved_at = now();

            // Physical cash requested by a Cashier uses a two-step handover:
            // Admin approves first, then the requesting Cashier confirms the
            // actual handover with PIN before the branch vault is mutated.
            if (
                $approver->role === 'admin'
                && $requester->role === 'cashier'
                && $locked->target_type === BalanceAdjustmentRequest::TARGET_CASH
            ) {
                $locked->status = BalanceAdjustmentRequest::STATUS_APPROVED;
                $locked->save();

                ActivityLog::query()->create([
                    'user_id' => $approver->id,
                    'action' => 'adjustment_request_approved',
                    'entity_type' => 'balance_adjustment_request',
                    'entity_id' => $locked->id,
                    'details' => [
                        'branch_id' => (int) $locked->branch_id,
                        'requested_by' => (int) $locked->requested_by,
                        'approver_id' => (int) $locked->approver_id,
                        'target_type' => $locked->target_type,
                        'direction' => $locked->direction,
                        'amount' => Money::normalize($locked->amount),
                        'next_step' => 'cashier_pin_handover_confirmation',
                    ],
                ]);

                return [$locked, false];
            }

            if ($locked->target_type === BalanceAdjustmentRequest::TARGET_ACCOUNT) {
                $this->applyAccountAdjustment($locked);
            } else {
                $this->applyCashAdjustment($approver, $locked);
            }

            $locked->status = BalanceAdjustmentRequest::STATUS_CONFIRMED;
            $locked->confirmed_by = $approver->id;
            $locked->confirmed_at = now();
            $locked->save();

            ActivityLog::query()->create([
                'user_id' => $approver->id,
                'action' => 'adjustment_request_confirmed',
                'entity_type' => 'balance_adjustment_request',
                'entity_id' => $locked->id,
                'details' => [
                    'branch_id' => (int) $locked->branch_id,
                    'requested_by' => (int) $locked->requested_by,
                    'approver_id' => (int) ($locked->approver_id ?? $approver->id),
                    'target_type' => $locked->target_type,
                    'account_id' => $locked->account_id,
                    'direction' => $locked->direction,
                    'amount' => Money::normalize($locked->amount),
                ],
            ]);

            return [$locked, true];
        });

        /** @var BalanceAdjustmentRequest $request */
        [$request, $balanceChanged] = $result;

        if ($balanceChanged) {
            $this->broadcasts->balanceUpdated((int) $request->branch_id);
        }

        return $this->loadRelations($request);
    }

    private function completeApprovedCash(
        User $cashier,
        BalanceAdjustmentRequest $adjustment,
    ): BalanceAdjustmentRequest {
        $confirmed = DB::transaction(function () use (
            $cashier,
            $adjustment,
        ): BalanceAdjustmentRequest {
            $locked = BalanceAdjustmentRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $locked->status !== BalanceAdjustmentRequest::STATUS_APPROVED
                || $locked->target_type !== BalanceAdjustmentRequest::TARGET_CASH
            ) {
                throw ValidationException::withMessages([
                    'request' => 'This cash request is not ready for handover confirmation.',
                ]);
            }

            $this->guardRequestingCashier($cashier, $locked);
            $this->applyCashAdjustment($cashier, $locked);

            $locked->status = BalanceAdjustmentRequest::STATUS_CONFIRMED;
            $locked->confirmed_by = $cashier->id;
            $locked->confirmed_at = now();
            $locked->save();

            ActivityLog::query()->create([
                'user_id' => $cashier->id,
                'action' => 'adjustment_request_cash_handover_confirmed',
                'entity_type' => 'balance_adjustment_request',
                'entity_id' => $locked->id,
                'details' => [
                    'branch_id' => (int) $locked->branch_id,
                    'requested_by' => (int) $locked->requested_by,
                    'approver_id' => (int) $locked->approver_id,
                    'direction' => $locked->direction,
                    'amount' => Money::normalize($locked->amount),
                    'confirmed_by' => $cashier->id,
                ],
            ]);

            return $locked;
        });

        $this->broadcasts->balanceUpdated((int) $confirmed->branch_id);

        return $this->loadRelations($confirmed);
    }

    private function rejectDecision(
        User $actor,
        BalanceAdjustmentRequest $adjustment,
        ?string $note,
    ): BalanceAdjustmentRequest {
        $rejected = DB::transaction(function () use (
            $actor,
            $adjustment,
            $note,
        ): BalanceAdjustmentRequest {
            $locked = BalanceAdjustmentRequest::query()
                ->withoutGlobalScopes()
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === BalanceAdjustmentRequest::STATUS_PENDING) {
                $this->guardApprover($actor, $locked);
            } elseif (
                $locked->status === BalanceAdjustmentRequest::STATUS_APPROVED
                && $locked->target_type === BalanceAdjustmentRequest::TARGET_CASH
            ) {
                $this->guardRequestingCashier($actor, $locked);
            } else {
                throw ValidationException::withMessages([
                    'request' => 'This request can no longer be rejected.',
                ]);
            }

            $locked->status = BalanceAdjustmentRequest::STATUS_REJECTED;
            $locked->rejected_by = $actor->id;
            $locked->rejected_at = now();
            $locked->rejection_note = trim((string) $note) ?: null;
            $locked->save();

            ActivityLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'adjustment_request_rejected',
                'entity_type' => 'balance_adjustment_request',
                'entity_id' => $locked->id,
                'details' => [
                    'branch_id' => (int) $locked->branch_id,
                    'requested_by' => (int) $locked->requested_by,
                    'approver_id' => (int) ($locked->approver_id ?? 0),
                    'rejection_note' => $locked->rejection_note,
                ],
            ]);

            return $locked;
        });

        return $this->loadRelations($rejected);
    }

    private function applyAccountAdjustment(
        BalanceAdjustmentRequest $adjustment,
    ): void {
        $account = Account::query()
            ->withoutGlobalScopes()
            ->whereKey($adjustment->account_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'request' => 'The target account is inactive or unavailable.',
            ]);
        }

        if (
            $account->branch_id !== null
            && (int) $account->branch_id !== (int) $adjustment->branch_id
        ) {
            throw ValidationException::withMessages([
                'request' => 'The target account no longer belongs to this branch.',
            ]);
        }

        $current = (float) Money::normalize($account->balance);
        $amount = (float) Money::normalize($adjustment->amount);

        if ($adjustment->direction === BalanceAdjustmentRequest::DIRECTION_WITHDRAW) {
            if ($current < $amount) {
                throw ValidationException::withMessages([
                    'request' => 'Account balance is no longer sufficient for this withdrawal.',
                ]);
            }

            $newBalance = $current - $amount;
        } else {
            $newBalance = $current + $amount;
        }

        $account->balance = Money::normalize($newBalance);
        $account->save();
    }

    private function applyCashAdjustment(
        User $approver,
        BalanceAdjustmentRequest $adjustment,
    ): void {
        $denominations = $this->normalizeDenominations(
            $adjustment->denominations_json ?? [],
        );

        if ($denominations === []) {
            throw ValidationException::withMessages([
                'request' => 'Cash denomination data is missing.',
            ]);
        }

        $isDeposit = $adjustment->direction
            === BalanceAdjustmentRequest::DIRECTION_DEPOSIT;

        if (! $isDeposit) {
            $available = $this->vault->getAvailableBalance(
                branchId: (int) $adjustment->branch_id,
            );

            foreach ($denominations as $denomination => $quantity) {
                if ($quantity > (int) ($available[$denomination] ?? 0)) {
                    throw ValidationException::withMessages([
                        'request' => "Branch vault no longer has enough {$denomination} MMK notes.",
                    ]);
                }
            }
        }

        $cashier = User::query()
            ->withoutGlobalScopes()
            ->findOrFail((int) $adjustment->assigned_cashier_id);
        $requester = User::query()
            ->withoutGlobalScopes()
            ->findOrFail((int) $adjustment->requested_by);
        $adminId = $requester->role === 'admin'
            ? (int) $requester->id
            : ($approver->role === 'admin'
                ? (int) $approver->id
                : (int) User::query()
                    ->withoutGlobalScopes()
                    ->where('role', 'admin')
                    ->value('id'));

        if ($adminId <= 0) {
            throw ValidationException::withMessages([
                'request' => 'Admin account is unavailable for this cash movement.',
            ]);
        }

        $verifiedBy = (int) ($adjustment->approver_id ?? $approver->id);
        $batchId = (string) Str::uuid();
        $entryType = $isDeposit ? 'vault_in' : 'vault_out';
        $movementType = $isDeposit
            ? 'admin_to_cashier'
            : 'cashier_to_admin';
        $sourceType = $isDeposit ? 'admin' : 'cashier_vault';
        $sourceId = $isDeposit ? $adminId : (int) $cashier->id;
        $destinationType = $isDeposit ? 'cashier_vault' : 'admin';
        $destinationId = $isDeposit ? (int) $cashier->id : $adminId;
        $note = sprintf(
            'Balance request #%d completed by user #%d after approval by user #%d.',
            $adjustment->id,
            $approver->id,
            $verifiedBy,
        );

        $this->vault->recordBulk(
            entryType: $entryType,
            denominations: $denominations,
            createdBy: $approver->id,
            note: $note,
            batchId: $batchId,
            movementType: $movementType,
            sourceType: $sourceType,
            sourceId: $sourceId,
            destinationType: $destinationType,
            destinationId: $destinationId,
            affectsMainVault: true,
            branchId: (int) $adjustment->branch_id,
        );

        foreach ($denominations as $denomination => $quantity) {
            if ($quantity <= 0) {
                continue;
            }

            VaultTransaction::query()
                ->withoutGlobalScopes()
                ->create([
                    'branch_id' => (int) $adjustment->branch_id,
                    'batch_id' => $batchId,
                    'txn_type' => 'adjustment',
                    'movement_type' => $movementType,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'destination_type' => $destinationType,
                    'destination_id' => $destinationId,
                    'denomination' => $denomination,
                    'quantity' => $quantity,
                    'performed_by' => (int) $adjustment->requested_by,
                    'verified_by' => $verifiedBy,
                    'note' => $note,
                ]);
        }
    }

    private function guardApprover(
        User $approver,
        BalanceAdjustmentRequest $adjustment,
    ): void {
        $expectedApproverId = (int) (
            $adjustment->approver_id
            ?? $adjustment->assigned_cashier_id
        );

        if (
            ! $approver->is_active
            || (int) $approver->id !== $expectedApproverId
            || ! in_array($approver->role, ['admin', 'cashier'], true)
        ) {
            throw ValidationException::withMessages([
                'request' => 'This request is assigned to another approver.',
            ]);
        }

        if (
            $approver->role === 'cashier'
            && (
                (int) $approver->branch_id !== (int) $adjustment->branch_id
                || (int) $adjustment->assigned_cashier_id !== (int) $approver->id
            )
        ) {
            throw ValidationException::withMessages([
                'request' => 'This request belongs to another Cashier or branch.',
            ]);
        }
    }

    private function guardRequestingCashier(
        User $cashier,
        BalanceAdjustmentRequest $adjustment,
    ): void {
        if (
            $cashier->role !== 'cashier'
            || ! $cashier->is_active
            || (int) $cashier->id !== (int) $adjustment->requested_by
            || (int) $cashier->id !== (int) $adjustment->assigned_cashier_id
            || (int) $cashier->branch_id !== (int) $adjustment->branch_id
        ) {
            throw ValidationException::withMessages([
                'request' => 'Only the requesting branch Cashier can confirm this cash handover.',
            ]);
        }
    }

    /** @return array<int, int> */
    private function normalizeDenominations(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $denomination => $quantity) {
            $denomination = (int) $denomination;
            $quantity = (int) $quantity;

            if (! in_array($denomination, Money::supportedDenominations(), true)) {
                throw ValidationException::withMessages([
                    'denominations' => "Unsupported denomination: {$denomination}",
                ]);
            }

            if ($quantity > 0) {
                $normalized[$denomination] = $quantity;
            }
        }

        return $normalized;
    }

    private function loadRelations(
        BalanceAdjustmentRequest $adjustment,
    ): BalanceAdjustmentRequest {
        return $adjustment->refresh()->load([
            'branch',
            'account.company',
            'requester',
            'assignedCashier',
            'approver',
            'confirmer',
            'rejecter',
        ]);
    }
}
