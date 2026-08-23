<?php

namespace App\Repositories;

use App\Exceptions\InsufficientFloatException;
use App\Models\CashFloatAssignment;
use App\Models\CashFloatDenomination;
use App\Models\CashFloatIssue;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CashFloatRepository
{
    public function find(int $id): ?CashFloatAssignment
    {
        return CashFloatAssignment::query()
            ->with(['denominations', 'employee', 'issuer'])
            ->find($id);
    }

    /**
     * @return Collection<int, CashFloatAssignment>
     */
    public function list(?int $employeeId = null, ?string $status = null): Collection
    {
        return CashFloatAssignment::query()
            ->with(['denominations', 'employee', 'issuer'])
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->get();
    }

    public function activeForEmployee(int $employeeId): ?CashFloatAssignment
    {
        return CashFloatAssignment::query()
            ->with(['denominations', 'employee', 'issuer'])
            ->where('employee_id', $employeeId)
            ->where('status', 'ACTIVE')
            ->orderByDesc('created_at')
            ->first();
    }

    public function pendingForEmployee(int $employeeId): ?CashFloatAssignment
    {
        return CashFloatAssignment::query()
            ->with(['denominations', 'employee', 'issuer'])
            ->where('employee_id', $employeeId)
            ->where('status', 'PENDING_RECEIPT')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @param  array<int, int>  $denominations  denomination => quantity
     */
    public function issue(
        int $employeeId,
        int $issuedBy,
        array $denominations,
        ?string $note = null,
    ): CashFloatAssignment {
        $total = Money::denominationTotal($denominations);

        return DB::transaction(function () use ($employeeId, $issuedBy, $denominations, $note, $total): CashFloatAssignment {
            $float = CashFloatAssignment::query()->create([
                'employee_id' => $employeeId,
                'issued_by' => $issuedBy,
                'status' => 'PENDING_RECEIPT',
                'total_amount' => Money::normalize($total),
                'note' => $note,
            ]);

            foreach ($denominations as $denomination => $quantity) {
                if ((int) $quantity <= 0) {
                    continue;
                }
                CashFloatDenomination::query()->create([
                    'float_id' => $float->id,
                    'denomination' => (int) $denomination,
                    'quantity' => (int) $quantity,
                ]);
            }

            CashFloatIssue::query()->create([
                'float_id' => $float->id,
                'employee_id' => $employeeId,
                'issued_by' => $issuedBy,
                'issue_type' => 'INITIAL',
                'status' => 'PENDING_RECEIPT',
                'amount' => Money::normalize($total),
                'denominations_json' => $denominations,
                'note' => $note,
            ]);

            return $float->refresh()->load(['denominations', 'employee', 'issuer', 'issues']);
        });
    }

    public function activate(CashFloatAssignment $float): CashFloatAssignment
    {
        $affected = CashFloatAssignment::query()
            ->where('id', $float->id)
            ->where('status', 'PENDING_RECEIPT')
            ->update([
                'status' => 'ACTIVE',
                'received_at' => now(),
                'current_balance' => DB::raw('total_amount'),
            ]);

        if ($affected === 0) {
            throw new \RuntimeException("Float #{$float->id} is not PENDING_RECEIPT.");
        }

        $initialIssue = CashFloatIssue::query()
            ->where('float_id', $float->id)
            ->where('issue_type', 'INITIAL')
            ->where('status', 'PENDING_RECEIPT')
            ->orderBy('id')
            ->first();

        if ($initialIssue !== null) {
            $initialIssue->status = 'RECEIVED';
            $initialIssue->received_at = now();
            $initialIssue->save();
        }

        return $float->refresh()->load(['denominations', 'employee', 'issuer', 'issues']);
    }

    /**
     * @param  array<int, int>  $returnDenominations
     */
    public function initiateReturn(CashFloatAssignment $float, array $returnDenominations): CashFloatAssignment
    {
        $affected = CashFloatAssignment::query()
            ->where('id', $float->id)
            ->where('status', 'ACTIVE')
            ->update([
                'status' => 'PENDING_RECONCILIATION',
                'return_denominations_json' => $returnDenominations,
            ]);

        if ($affected === 0) {
            throw new \RuntimeException("Float #{$float->id} is not ACTIVE.");
        }

        return $float->refresh()->load(['denominations', 'employee', 'issuer']);
    }

    public function confirmReturn(CashFloatAssignment $float, float|string $closingTotal): CashFloatAssignment
    {
        $affected = CashFloatAssignment::query()
            ->where('id', $float->id)
            ->where('status', 'PENDING_RECONCILIATION')
            ->update([
                'status' => 'CLOSED',
                'closed_at' => now(),
                'closing_total' => Money::normalize($closingTotal),
                'current_balance' => Money::normalize(0),
            ]);

        if ($affected === 0) {
            throw new \RuntimeException("Float #{$float->id} is not PENDING_RECONCILIATION.");
        }

        return $float->refresh()->load(['denominations', 'employee', 'issuer']);
    }

    /**
     * @param  array<int, int>  $returnDenominations
     */
    public function rejectPendingReceipt(CashFloatAssignment $float, array $returnDenominations, ?string $note = null): CashFloatAssignment
    {
        $updates = [
            'status' => 'CLOSED',
            'closed_at' => now(),
            'closing_total' => Money::normalize(Money::denominationTotal($returnDenominations)),
            'current_balance' => Money::normalize(0),
            'return_denominations_json' => $returnDenominations,
        ];

        if ($note !== null && trim($note) !== '') {
            $existingNote = trim((string) ($float->note ?? ''));
            $updates['note'] = $existingNote === ''
                ? trim($note)
                : $existingNote.PHP_EOL.trim($note);
        }

        $affected = CashFloatAssignment::query()
            ->where('id', $float->id)
            ->where('status', 'PENDING_RECEIPT')
            ->update($updates);

        if ($affected === 0) {
            throw new \RuntimeException("Float #{$float->id} is not PENDING_RECEIPT.");
        }

        $initialIssue = CashFloatIssue::query()
            ->where('float_id', $float->id)
            ->where('issue_type', 'INITIAL')
            ->where('status', 'PENDING_RECEIPT')
            ->orderBy('id')
            ->first();

        if ($initialIssue !== null) {
            $initialIssue->status = 'REJECTED';
            $initialIssue->rejected_at = now();
            $initialIssue->save();
        }

        return $float->refresh()->load(['denominations', 'employee', 'issuer', 'issues']);
    }

    /**
     * Create an additional issue against an already ACTIVE float session.
     * The money is not added to Teller on-hand until the Teller confirms receipt.
     *
     * @param  array<int, int>  $denominations
     */
    public function createAdditionalIssue(
        CashFloatAssignment $float,
        int $issuedBy,
        array $denominations,
        ?string $note = null,
    ): CashFloatIssue {
        $total = Money::denominationTotal($denominations);

        $current = CashFloatAssignment::query()
            ->whereKey($float->id)
            ->where('status', 'ACTIVE')
            ->first();

        if ($current === null) {
            throw new \RuntimeException("Float #{$float->id} is not ACTIVE.");
        }

        return CashFloatIssue::query()->create([
            'float_id' => $current->id,
            'employee_id' => $current->employee_id,
            'issued_by' => $issuedBy,
            'issue_type' => 'ADDITIONAL',
            'status' => 'PENDING_RECEIPT',
            'amount' => Money::normalize($total),
            'denominations_json' => $denominations,
            'note' => $note,
        ])->load(['float', 'employee', 'issuer']);
    }

    /**
     * @return Collection<int, CashFloatIssue>
     */
    public function issuesForEmployee(int $employeeId, ?string $status = null): Collection
    {
        return CashFloatIssue::query()
            ->with(['float', 'employee', 'issuer'])
            ->where('employee_id', $employeeId)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Atomically merge a confirmed additional issue into the ACTIVE float.
     */
    public function receiveAdditionalIssue(CashFloatIssue $issue): CashFloatAssignment
    {
        return DB::transaction(function () use ($issue): CashFloatAssignment {
            $lockedIssue = CashFloatIssue::query()
                ->whereKey($issue->id)
                ->where('status', 'PENDING_RECEIPT')
                ->lockForUpdate()
                ->first();

            if ($lockedIssue === null) {
                throw new \RuntimeException("Float issue #{$issue->id} is not PENDING_RECEIPT.");
            }

            $float = CashFloatAssignment::query()
                ->whereKey($lockedIssue->float_id)
                ->where('employee_id', $lockedIssue->employee_id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if ($float === null) {
                throw new \RuntimeException("Float #{$lockedIssue->float_id} is not ACTIVE.");
            }

            $denominations = array_map('intval', $lockedIssue->denominations_json ?? []);
            foreach ($denominations as $denomination => $quantity) {
                $denomination = (int) $denomination;
                $quantity = (int) $quantity;
                if ($quantity <= 0) {
                    continue;
                }

                $line = CashFloatDenomination::query()
                    ->where('float_id', $float->id)
                    ->where('denomination', $denomination)
                    ->lockForUpdate()
                    ->first();

                if ($line === null) {
                    CashFloatDenomination::query()->create([
                        'float_id' => $float->id,
                        'denomination' => $denomination,
                        'quantity' => $quantity,
                    ]);
                } else {
                    $line->quantity = (int) $line->quantity + $quantity;
                    $line->save();
                }
            }

            $amount = Money::normalize($lockedIssue->amount);
            $float->current_balance = Money::normalize((float) ($float->current_balance ?? 0) + (float) $amount);
            $float->total_amount = Money::normalize((float) $float->total_amount + (float) $amount);
            $float->save();

            $lockedIssue->status = 'RECEIVED';
            $lockedIssue->received_at = now();
            $lockedIssue->save();

            return $float->refresh()->load(['denominations', 'employee', 'issuer', 'issues']);
        });
    }

    public function rejectAdditionalIssue(CashFloatIssue $issue): CashFloatIssue
    {
        $affected = CashFloatIssue::query()
            ->whereKey($issue->id)
            ->where('status', 'PENDING_RECEIPT')
            ->update([
                'status' => 'REJECTED',
                'rejected_at' => now(),
            ]);

        if ($affected === 0) {
            throw new \RuntimeException("Float issue #{$issue->id} is not PENDING_RECEIPT.");
        }

        return $issue->refresh()->load(['float', 'employee', 'issuer']);
    }

    /**
     * @return array<int, int> denomination => quantity
     */
    public function getDenominationBalance(int $floatId): array
    {
        $rows = CashFloatDenomination::query()
            ->where('float_id', $floatId)
            ->get(['denomination', 'quantity']);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->denomination] = (int) $row->quantity;
        }

        return $result;
    }

    /**
     * Atomically decrement per-denomination quantities on a float. Each row is
     * updated with `WHERE quantity >= ?` so concurrent deductions cannot leave
     * a negative denomination count. Mirrors Python
     * CashFloatRepository.deduct_denominations.
     *
     * @param  array<int, int>  $denominations
     */
    public function deductDenominations(int $floatId, array $denominations): void
    {
        DB::transaction(function () use ($floatId, $denominations): void {
            foreach ($denominations as $denomination => $quantity) {
                $denomination = (int) $denomination;
                $quantity = (int) $quantity;
                if ($quantity <= 0) {
                    continue;
                }

                $affected = CashFloatDenomination::query()
                    ->where('float_id', $floatId)
                    ->where('denomination', $denomination)
                    ->where('quantity', '>=', $quantity)
                    ->update(['quantity' => DB::raw("quantity - {$quantity}")]);

                if ($affected === 0) {
                    throw new \RuntimeException(
                        "Denomination {$denomination} MMK is exhausted on float #{$floatId}."
                    );
                }
            }
        });
    }

    /**
     * Atomically add received cash notes to an active employee float.
     *
     * @param  array<int, int>  $denominations
     */
    public function addDenominations(int $floatId, array $denominations): void
    {
        Money::denominationTotal($denominations);

        DB::transaction(function () use ($floatId, $denominations): void {
            $float = CashFloatAssignment::query()
                ->where('id', $floatId)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if ($float === null) {
                throw new \RuntimeException("No active float #{$floatId}.");
            }

            foreach ($denominations as $denomination => $quantity) {
                $denomination = (int) $denomination;
                $quantity = (int) $quantity;
                if ($quantity <= 0) {
                    continue;
                }

                $line = CashFloatDenomination::query()
                    ->where('float_id', $floatId)
                    ->where('denomination', $denomination)
                    ->lockForUpdate()
                    ->first();

                if ($line === null) {
                    CashFloatDenomination::query()->create([
                        'float_id' => $floatId,
                        'denomination' => $denomination,
                        'quantity' => $quantity,
                    ]);
                } else {
                    $line->quantity = (int) $line->quantity + $quantity;
                    $line->save();
                }
            }
        });
    }

    /**
     * Add received cash notes to the employee's active float.
     *
     * @param  array<int, int>  $denominations
     */
    public function addDenominationsForEmployee(int $employeeId, array $denominations): void
    {
        $active = $this->activeForEmployee($employeeId);
        if ($active === null) {
            throw new \RuntimeException("No active float for employee #{$employeeId}.");
        }

        $this->addDenominations($active->id, $denominations);
    }

    public function incrementBalance(int $employeeId, float|string $amount): string
    {
        $normalized = Money::normalize($amount);
        if ((float) $normalized <= 0) {
            throw new \InvalidArgumentException('Float increment must be greater than zero.');
        }

        return DB::transaction(function () use ($employeeId, $normalized): string {
            $active = CashFloatAssignment::query()
                ->where('employee_id', $employeeId)
                ->where('status', 'ACTIVE')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($active === null) {
                throw new \RuntimeException("No active float for employee #{$employeeId}.");
            }

            $active->current_balance = Money::normalize((float) ($active->current_balance ?? 0) + (float) $normalized);
            $active->save();

            return Money::normalize($active->current_balance);
        });
    }

    public function deductBalance(int $employeeId, float|string $amount): string
    {
        $normalized = Money::normalize($amount);

        return DB::transaction(function () use ($employeeId, $normalized): string {
            $affected = CashFloatAssignment::query()
                ->where('employee_id', $employeeId)
                ->where('status', 'ACTIVE')
                ->where('current_balance', '>=', $normalized)
                ->update(['current_balance' => DB::raw("current_balance - {$normalized}")]);

            if ($affected === 0) {
                $active = CashFloatAssignment::query()
                    ->where('employee_id', $employeeId)
                    ->where('status', 'ACTIVE')
                    ->orderByDesc('created_at')
                    ->first();

                if ($active === null) {
                    throw new \RuntimeException("No active float for employee #{$employeeId}.");
                }

                throw new InsufficientFloatException(
                    Money::normalize($active->current_balance ?? 0),
                    $normalized,
                );
            }

            $active = CashFloatAssignment::query()
                ->where('employee_id', $employeeId)
                ->where('status', 'ACTIVE')
                ->orderByDesc('created_at')
                ->first();

            return Money::normalize($active->current_balance ?? 0);
        });
    }
}
