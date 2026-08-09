<?php

namespace App\Models;

use App\Enums\AccountFeature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'feature'])]
class AccountFeatureAssignment extends Model
{
    protected $table = 'account_features';

    protected function casts(): array
    {
        return [
            'feature' => AccountFeature::class,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
