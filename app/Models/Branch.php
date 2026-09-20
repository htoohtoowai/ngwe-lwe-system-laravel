<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['code', 'name', 'address', 'phone', 'is_active'])]
class Branch extends Model
{
    public const MAIN_CODE = 'MAIN';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function cashier(): HasOne
    {
        return $this->hasOne(User::class)
            ->where('role', 'cashier');
    }

    public function tellers(): HasMany
    {
        return $this->hasMany(User::class)
            ->where('role', 'teller');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function cashFloats(): HasMany
    {
        return $this->hasMany(CashFloatAssignment::class);
    }

    public static function main(): self
    {
        return self::query()
            ->where('code', self::MAIN_CODE)
            ->firstOrFail();
    }
}
