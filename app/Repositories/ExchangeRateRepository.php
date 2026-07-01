<?php

namespace App\Repositories;

use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Collection;

class ExchangeRateRepository
{
    public function getLatest(string $baseCurrency = 'THB', string $quoteCurrency = 'MMK'): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('base_currency', $baseCurrency)
            ->where('quote_currency', $quoteCurrency)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, ExchangeRate>
     */
    public function recent(int $limit = 50): Collection
    {
        return ExchangeRate::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 200)))
            ->get();
    }

    public function find(int $id): ?ExchangeRate
    {
        return ExchangeRate::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ExchangeRate
    {
        return ExchangeRate::query()->create($data)->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ExchangeRate $rate, array $data): ExchangeRate
    {
        $rate->fill($data);
        $rate->save();

        return $rate->refresh();
    }

    public function delete(ExchangeRate $rate): void
    {
        $rate->delete();
    }
}
