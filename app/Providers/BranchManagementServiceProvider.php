<?php

namespace App\Providers;

use App\Http\Controllers\AdminBranchController;
use App\Http\Controllers\AdminBranchUserController;
use App\Models\Branch;
use App\Models\Transaction;
use App\Services\AdminOperationsDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class BranchManagementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'role:admin'])
            ->prefix('admin')
            ->group(function (): void {
                Route::get('/branches', function (Request $request) {
                    return $this->renderBranchPage($request);
                })->name('admin.branches.index');

                Route::get('/branches/create', function (Request $request) {
                    return $this->renderBranchPage($request, 'create');
                })->name('admin.branches.create');

                Route::get('/branches/{branch}/edit', function (Request $request, Branch $branch) {
                    return $this->renderBranchPage($request, 'edit', $branch->id);
                })->name('admin.branches.edit');

                Route::get('/branches/{branch}', function (Request $request, Branch $branch) {
                    return $this->renderBranchPage($request, 'detail', $branch->id);
                })->name('admin.branches.show');

                Route::prefix('branch-management')->group(function (): void {
                    Route::post('/branches', [AdminBranchController::class, 'store'])
                        ->name('admin.branch-management.branches.store');
                    Route::patch('/branches/{branch}', [AdminBranchController::class, 'update'])
                        ->name('admin.branch-management.branches.update');
                    Route::patch('/branches/{branch}/status', [AdminBranchController::class, 'toggle'])
                        ->name('admin.branch-management.branches.status');

                    Route::post('/users', [AdminBranchUserController::class, 'store'])
                        ->name('admin.branch-management.users.store');
                    Route::patch('/users/{user}', [AdminBranchUserController::class, 'update'])
                        ->name('admin.branch-management.users.update');
                    Route::patch('/users/{user}/status', [AdminBranchUserController::class, 'toggle'])
                        ->name('admin.branch-management.users.status');
                    Route::post('/users/{user}/reset-password', [AdminBranchUserController::class, 'resetPassword'])
                        ->name('admin.branch-management.users.reset-password');
                    Route::post('/users/{user}/pin', [AdminBranchUserController::class, 'setPin'])
                        ->name('admin.branch-management.users.pin');
                });
            });
    }

    private function renderBranchPage(
        Request $request,
        string $mode = 'list',
        ?int $resourceId = null,
    ) {
        return Inertia::render('admin/Branches', [
            'role' => 'admin',
            'mode' => $mode,
            'resourceId' => $resourceId,
            'announcement' => 'Manage branches and branch staffing.',
            'notificationCount' => Transaction::query()
                ->whereIn('transaction_type', ['cash_in', 'send_money'])
                ->where('status', 'PENDING_CASHIER_CONFIRM')
                ->count(),
            'adminData' => app(AdminOperationsDataService::class)->get($request),
        ]);
    }
}
