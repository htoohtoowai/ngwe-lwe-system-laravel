<?php

namespace App\Repositories;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AccountRepository
{
    public function all(
        ?int $serviceTypeId = null,
        ?int $companyId = null,
        bool $feeOnly = false,
        bool $includeInactive = false
    ): Collection {
        return Account::query()
            ->with('serviceType.company')
            ->when($serviceTypeId !== null, fn ($query) => $query->where('service_type_id', $serviceTypeId))
            ->when($companyId !== null, function ($query) use ($companyId): void {
                $query->whereHas('serviceType', fn ($serviceTypeQuery) => $serviceTypeQuery->where('company_id', $companyId));
            })
            ->when($feeOnly, fn ($query) => $query->where('is_fee_account', true))
            ->when(! $includeInactive, fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('serviceType', fn ($serviceQuery) => $serviceQuery
                    ->where('is_active', true)
                    ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true))))
            ->orderBy('account_name')
            ->get();
    }

    public function active(): Collection
    {
        return Account::query()
            ->where('is_active', true)
            ->whereHas('serviceType', fn ($serviceQuery) => $serviceQuery
                ->where('is_active', true)
                ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true)))
            ->with('serviceType.company')
            ->orderBy('account_name')
            ->get();
    }

    public function activeForOperation(string $operation): Collection
    {
        return Account::query()
            ->where('is_active', true)
            ->whereHas('serviceType', fn ($serviceQuery) => $serviceQuery
                ->where('is_active', true)
                ->whereIn('operation', [$operation, 'All'])
                ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true)))
            ->with('serviceType.company')
            ->orderBy('account_name')
            ->get();
    }

    public function feeAccounts(): Collection
    {
        return Account::query()
            ->where('is_fee_account', true)
            ->where('is_active', true)
            ->whereHas('serviceType', fn ($serviceQuery) => $serviceQuery
                ->where('is_active', true)
                ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true)))
            ->orderBy('account_name')
            ->get();
    }

    public function findActive(int $id): ?Account
    {
        return Account::query()
            ->where('is_active', true)
            ->whereHas('serviceType', fn ($serviceQuery) => $serviceQuery
                ->where('is_active', true)
                ->whereHas('company', fn ($companyQuery) => $companyQuery->where('is_active', true)))
            ->find($id);
    }

    public function find(int $id): ?Account
    {
        return Account::query()
            ->with('serviceType.company')
            ->find($id);
    }

    /**
     * @param  array{service_type_id:int,account_name:string,phone_number:string,balance:string,commission_rate?:string,is_active?:bool,is_fee_account?:bool}  $data
     */
    public function create(array $data): Account
    {
        return Account::query()->create($data)->load('serviceType.company');
    }

    /**
     * @param  array{service_type_id?:int,account_name?:string,phone_number?:string,balance?:string,commission_rate?:string,is_active?:bool,is_fee_account?:bool}  $data
     */
    public function update(Account $account, array $data): Account
    {
        $account->fill($data);
        $account->save();

        return $account->refresh()->load('serviceType.company');
    }

    public function deactivate(Account $account): Account
    {
        $account->is_active = false;
        $account->save();

        return $account->refresh()->load('serviceType.company');
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
