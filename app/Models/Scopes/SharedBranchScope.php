<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class SharedBranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $guard = Auth::guard();

        if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
            return;
        }

        $user = $guard->user();

        if ($user === null || $user->role === 'admin' || $user->branch_id === null) {
            return;
        }

        $branchColumn = $model->qualifyColumn('branch_id');

        $builder->where(function (Builder $query) use ($branchColumn, $user): void {
            $query->whereNull($branchColumn)
                ->orWhere($branchColumn, (int) $user->branch_id);
        });
    }
}
