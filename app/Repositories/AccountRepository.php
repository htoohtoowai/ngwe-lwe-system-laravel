<?php

namespace App\Repositories;

use App\Enums\AccountFeature;
use App\Enums\AccountType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AccountRepository
{
    public function all(
        ?int $companyId = null,
        bool $feeOnly = false,
        bool $includeInactive = false
    ): Collection {
        return Account::query()
            ->with(['company', 'featureAssignments'])
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when($feeOnly, fn ($query) => $query->where('is_fee_account', true))
            ->when(! $includeInactive, fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true)))
            ->orderBy('account_name')
            ->get();
    }

    public function active(): Collection
    {
        return Account::query()
            ->where('is_active', true)
            ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true))
            ->with(['company', 'featureAssignments'])
            ->orderBy('account_name')
            ->get();
    }

    public function activeForFeature(AccountFeature $feature): Collection
    {
        return Account::query()
            ->where('is_active', true)
            ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true))
            ->whereHas('featureAssignments', fn ($featureQuery) => $featureQuery->where('feature', $feature->value))
            ->with(['company', 'featureAssignments'])
            ->orderBy('account_name')
            ->get();
    }

    /**
     * Send Money / Receive Money may only use active PAY agent accounts.
     */
    public function activePayAgentsForFeature(AccountFeature $feature): Collection
    {
        return Account::query()
            ->where('account_type', AccountType::Pay->value)
            ->where('is_agent', true)
            ->where('is_active', true)
            ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true))
            ->whereHas('featureAssignments', fn ($featureQuery) => $featureQuery
                ->where('feature', $feature->value))
            ->with(['company', 'featureAssignments'])
            ->orderBy('account_name')
            ->get();
    }

    public function feeAccounts(): Collection
    {
        return Account::query()
            ->where('is_fee_account', true)
            ->where('is_active', true)
            ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true))
            ->with(['company', 'featureAssignments'])
            ->orderBy('account_name')
            ->get();
    }

    public function findActive(int $id): ?Account
    {
        return Account::query()
            ->where('is_active', true)
            ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true))
            ->with(['company', 'featureAssignments'])
            ->find($id);
    }

    public function find(int $id): ?Account
    {
        return Account::query()
            ->with(['company', 'featureAssignments'])
            ->find($id);
    }

    /**
     * @param  array{company_id:int,account_name:string,account_type:string,account_identifier:string,balance:string,is_active?:bool,is_fee_account?:bool,is_agent?:bool}  $data
     */
    public function create(array $data): Account
    {
        return Account::query()->create($data)->load(['company', 'featureAssignments']);
    }

    /**
     * @param  array{company_id?:int,account_name?:string,account_type?:string,account_identifier?:string,balance?:string,is_active?:bool,is_fee_account?:bool,is_agent?:bool}  $data
     */
    public function update(Account $account, array $data): Account
    {
        $account->fill($data);
        $account->save();

        return $account->refresh()->load(['company', 'featureAssignments']);
    }

    public function deactivate(Account $account): Account
    {
        $account->is_active = false;
        $account->save();

        return $account->refresh()->load(['company', 'featureAssignments']);
    }

    /**
     * Atomically apply a signed delta to an active account balance.
     * Returns the refreshed Account, or null when the account is inactive/missing.
     * Mirrors Python AccountRepository.increment_balance.
     */
    public function incrementBalance(int $accountId, float|string $delta): ?Account
    {
        $normalized = Money::normalize($delta);

        return DB::transaction(function () use ($accountId, $normalized): ?Account {
            $affected = Account::query()
                ->where('id', $accountId)
                ->where('is_active', true)
                ->update(['balance' => DB::raw('balance + '.$normalized)]);

            if ($affected === 0) {
                return null;
            }

            return Account::query()->find($accountId);
        });
    }

    /**
     * Atomically debit an active account balance, guarding against overdraw.
     * Throws InsufficientBalanceException if the account would go negative.
     * Used by cash-in / transfer flows where the source digital wallet is drawn down.
     */
    public function debitBalance(int $accountId, float|string $amount): Account
    {
        $normalized = Money::normalize($amount);

        if ((float) $normalized <= 0) {
            throw new \InvalidArgumentException('Debit amount must be greater than zero.');
        }

        return DB::transaction(function () use ($accountId, $normalized): Account {
            $account = Account::query()
                ->where('id', $accountId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                throw new \RuntimeException("Active account #{$accountId} not found.");
            }

            $available = Money::normalize($account->balance);

            if ((float) $available < (float) $normalized) {
                throw new InsufficientBalanceException($accountId, $available, $normalized);
            }

            $account->balance = Money::normalize((float) $available - (float) $normalized);
            $account->save();

            return $account->refresh();
        });
    }
}
