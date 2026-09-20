<?php

namespace App\Models;

use App\Models\Concerns\HasBranchScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'denomination_id', 'quantity', 'total_value', 'last_updated'])]
class BranchVaultDenominationBalance extends Model
{
    use HasBranchScope;

    public $timestamps = false;

    protected $table = 'branch_vault_denomination_balances';

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'denomination_id' => 'integer',
            'quantity' => 'integer',
            'total_value' => 'integer',
            'last_updated' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
