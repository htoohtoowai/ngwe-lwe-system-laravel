<?php

namespace App\Models\Concerns;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait HasBranchScope
{
    public static function bootHasBranchScope(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('branch_id') !== null) {
                return;
            }

            $guard = Auth::guard();

            if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
                return;
            }

            $user = $guard->user();

            if ($user?->branch_id !== null) {
                $model->setAttribute('branch_id', (int) $user->branch_id);
            }
        });
    }
}
