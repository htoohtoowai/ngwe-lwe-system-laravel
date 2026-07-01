<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['denomination_id', 'quantity', 'total_value'])]
class VaultDenominationBalance extends Model
{
    public $timestamps = false;

    protected $table = 'vault_denomination_balances';

    protected $primaryKey = 'denomination_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'denomination_id' => 'integer',
            'quantity' => 'integer',
            'total_value' => 'integer',
            'last_updated' => 'datetime',
        ];
    }
}
