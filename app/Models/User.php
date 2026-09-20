<?php

namespace App\Models;

use App\Models\Concerns\HasBranchScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'username', 'password', 'pin_hash', 'full_name', 'role', 'branch_id', 'is_active', 'auth_version'])]
#[Hidden(['password', 'pin_hash', 'remember_token', 'cashier_branch_id', 'admin_guard'])]
class User extends Authenticatable
{
    use HasBranchScope, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->role === 'admin') {
                $user->branch_id = null;
                $user->cashier_branch_id = null;
                $user->admin_guard = 1;

                return;
            }

            if ($user->branch_id === null) {
                $user->branch_id = Branch::main()->id;
            }

            $user->admin_guard = null;
            $user->cashier_branch_id = $user->role === 'cashier'
                ? (int) $user->branch_id
                : null;
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'created_by');
    }

    public function issuedCashFloats(): HasMany
    {
        return $this->hasMany(CashFloatAssignment::class, 'issued_by');
    }

    public function employeeCashFloats(): HasMany
    {
        return $this->hasMany(CashFloatAssignment::class, 'employee_id');
    }

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'cashier_branch_id' => 'integer',
            'admin_guard' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'auth_version' => 'integer',
        ];
    }
}
