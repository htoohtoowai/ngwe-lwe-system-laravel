<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashFloatController;
use App\Http\Controllers\Api\CashierController;
use App\Http\Controllers\Api\CommissionTierController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\RealtimeBroadcastController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ServiceTypeController;
use App\Http\Controllers\Api\SystemCompatibilityController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VaultController;
use App\Models\CashFloatAssignment;
use Illuminate\Support\Facades\Route;

Route::model('float', CashFloatAssignment::class);

Route::get('/health', fn () => [
    'status' => 'ok',
])->middleware('throttle:60,1');

Route::get('/system/status', fn () => [
    'name' => 'Ngwe Lwe System',
    'domain' => 'money-transfer',
    'status' => 'migration_in_progress',
])->middleware('throttle:60,1');

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('ngwe.auth');
    Route::get('/me', [AuthController::class, 'me'])->middleware('ngwe.auth');
    Route::post('/pin', [AuthController::class, 'setPin'])->middleware(['ngwe.auth', 'throttle:5,1']);
    Route::post('/password', [AuthController::class, 'changePassword'])->middleware(['ngwe.auth', 'throttle:5,1']);
});

Route::get('/admin/status', fn () => ['role' => 'admin'])
    ->middleware(['ngwe.auth', 'role:admin']);

Route::get('/cashier/status', fn () => ['role' => 'cashier'])
    ->middleware(['ngwe.auth', 'role:cashier']);

Route::get('/teller/status', fn () => ['role' => 'teller'])
    ->middleware(['ngwe.auth', 'role:teller']);

Route::middleware('ngwe.auth')->group(function (): void {
    Route::post('/ws-ticket', [SystemCompatibilityController::class, 'wsTicket']);
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::get('/companies/{company}/service-types', [CompanyController::class, 'serviceTypes']);
    Route::get('/companies/{company}/logo', [CompanyController::class, 'logo']);
    Route::get('/service-types', [ServiceTypeController::class, 'index']);
    Route::get('/services', [ServiceTypeController::class, 'index']);
    Route::get('/service-types/{serviceType}', [ServiceTypeController::class, 'show']);
    Route::get('/accounts', [AccountController::class, 'index']);
    Route::get('/accounts/{account}', [AccountController::class, 'show']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/accounts', [DashboardController::class, 'accounts']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/recent', [TransactionController::class, 'recent']);
    Route::get('/transactions/by-date', [TransactionController::class, 'byDate']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
    Route::post('/transactions/cash-out', [TransactionController::class, 'cashOut']);
    Route::post('/transactions/transfer', [TransactionController::class, 'transfer']);
    Route::post('/transactions/exchange', [TransactionController::class, 'exchange']);

    Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
    Route::get('/exchange-rates/latest', [ExchangeRateController::class, 'latest']);
    Route::get('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'show']);

    Route::get('/reports/daily', [ReportController::class, 'daily']);
    Route::get('/reconciliation/current', [ReportController::class, 'current']);
    Route::get('/reconciliation/history', [ReportController::class, 'history']);

    Route::get('/commission-tiers', [CommissionTierController::class, 'index']);
    Route::get('/commission-tiers/lookup', [CommissionTierController::class, 'lookup']);

    Route::get('/cash-floats', [CashFloatController::class, 'index']);
    Route::get('/cash-floats/my-pending', [CashFloatController::class, 'myPending']);
    Route::get('/cash-floats/{float}', [CashFloatController::class, 'show']);

    Route::get('/vault/balance', [VaultController::class, 'balance']);
    Route::get('/vault/inventory', [VaultController::class, 'inventory']);

    // Reference-project user settings compatibility routes.
    Route::get('/users/employees', [UserController::class, 'employees']);
    Route::post('/users/change-password', [UserController::class, 'changePasswordCompat']);
    Route::post('/users/change-pin', [UserController::class, 'changePin']);
    Route::post('/users/{user}/pin', [UserController::class, 'setUserPin']);
});

Route::middleware(['ngwe.auth', 'role:admin'])->group(function (): void {
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::post('/commission-tiers', [CommissionTierController::class, 'store']);
    Route::put('/commission-tiers/{commissionTier}', [CommissionTierController::class, 'update']);
    Route::patch('/commission-tiers/{commissionTier}', [CommissionTierController::class, 'update']);
    Route::delete('/commission-tiers/{commissionTier}', [CommissionTierController::class, 'destroy']);
});

Route::middleware(['ngwe.auth', 'role:teller'])->group(function (): void {
    Route::post('/transactions/cash-in', [TransactionController::class, 'cashIn']);
    Route::post('/cash-floats/{float}/activate', [CashFloatController::class, 'activate']);
    Route::post('/cash-floats/{float}/initiate-return', [CashFloatController::class, 'initiateReturn']);
});

Route::middleware(['ngwe.auth', 'role:cashier'])->group(function (): void {
    Route::post('/vault/entries', [VaultController::class, 'storeEntry']);
    Route::post('/cash-floats', [CashFloatController::class, 'store']);
    Route::post('/cash-floats/{float}/confirm-return', [CashFloatController::class, 'confirmReturn']);
});

Route::prefix('cashier')->middleware('ngwe.auth')->group(function (): void {
    Route::get('/employees', [CashierController::class, 'employees'])->middleware('role:cashier');
    Route::get('/pending-cash-ins', [CashierController::class, 'pendingCashIns'])->middleware('role:cashier');
    Route::get('/vault', [CashierController::class, 'vault']);
    Route::post('/vault/entry', [VaultController::class, 'storeEntry'])->middleware('role:cashier');
    Route::get('/vault/logs', [CashierController::class, 'vaultLogs']);
    Route::get('/vault/denominations', [CashierController::class, 'vault']);
    Route::get('/denominations', [CashierController::class, 'denominations']);
    Route::get('/vault/inventory', [VaultController::class, 'inventory']);
    Route::get('/floats', [CashFloatController::class, 'index']);
    Route::get('/floats/my-pending', [CashFloatController::class, 'myPending']);
    Route::get('/floats/{float}', [CashFloatController::class, 'show']);
    Route::get('/floats/{float}/denominations', [CashFloatController::class, 'denominations']);
    Route::post('/floats', [CashFloatController::class, 'store'])->middleware('role:cashier');
    Route::post('/floats/{float}/receive', [CashFloatController::class, 'activate'])->middleware('role:teller');
    Route::post('/floats/{float}/initiate-return', [CashFloatController::class, 'initiateReturn'])->middleware('role:teller');
    Route::post('/floats/{float}/confirm-return', [CashFloatController::class, 'confirmReturn'])->middleware('role:cashier');
    Route::post('/transactions/{transaction}/confirm-cash-in', [TransactionController::class, 'confirmCashIn'])->middleware('role:cashier');
    Route::post('/transactions/{transaction}/cancel-cash-in', [TransactionController::class, 'cancelCashIn'])->middleware('role:cashier');
    Route::post('/transactions/{transaction}/approve', [TransactionController::class, 'approve'])->middleware('role:cashier');
    Route::post('/transactions/{transaction}/payment', [TransactionController::class, 'recordPayment'])->middleware('role:cashier');
});

Route::middleware(['ngwe.auth', 'role:cashier'])->group(function (): void {
    Route::post('/transactions/{transaction}/confirm-cash-in', [TransactionController::class, 'confirmCashIn']);
    Route::post('/transactions/{transaction}/cancel-cash-in', [TransactionController::class, 'cancelCashIn']);
    Route::post('/transactions/{transaction}/approve', [TransactionController::class, 'approve']);
    Route::post('/transactions/{transaction}/payment', [TransactionController::class, 'recordPayment']);
});

Route::middleware(['ngwe.auth', 'role:admin'])->group(function (): void {
    Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy']);
});

Route::middleware(['ngwe.auth', 'role:admin'])->group(function (): void {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
    Route::patch('/users/{user}/active', [UserController::class, 'toggleActive']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    Route::post('/companies', [CompanyController::class, 'store']);
    Route::patch('/companies/{company}', [CompanyController::class, 'update']);
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy']);
    Route::post('/companies/{company}/service-types', [CompanyController::class, 'storeServiceType']);
    Route::post('/companies/{company}/logo', [CompanyController::class, 'uploadLogo']);

    Route::post('/service-types', [ServiceTypeController::class, 'store']);
    Route::patch('/service-types/{serviceType}', [ServiceTypeController::class, 'update']);
    Route::delete('/service-types/{serviceType}', [ServiceTypeController::class, 'destroy']);

    Route::post('/accounts', [AccountController::class, 'store']);
    Route::patch('/accounts/{account}', [AccountController::class, 'update']);
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy']);
    Route::post('/accounts/{account}/balance-adjust', [AccountController::class, 'adjustBalance']);

    Route::get('/vault/log', [VaultController::class, 'log']);

    Route::get('/reports/daily-summary', [ReportController::class, 'dailySummary']);
    Route::post('/reports/daily-reconciliation', [ReportController::class, 'closeDailyReconciliation']);
    Route::get('/reports/daily-reconciliations', [ReportController::class, 'dailyReconciliations']);

    Route::post('/exchange-rates', [ExchangeRateController::class, 'store']);
    Route::patch('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'update']);
    Route::delete('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'destroy']);

    Route::post('/broadcast/test', [RealtimeBroadcastController::class, 'test']);
    Route::post('/system/backup', [SystemCompatibilityController::class, 'backup']);
    Route::post('/reconciliation/close-day', [ReportController::class, 'closeDay']);
});
