<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashFloatController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\RealtimeBroadcastController;
use App\Http\Controllers\Api\ServiceTypeController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\VaultController;
use App\Models\CashFloatAssignment;
use Illuminate\Support\Facades\Route;

Route::model('float', CashFloatAssignment::class);

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
});

Route::get('/owner/status', fn () => ['role' => 'owner'])
    ->middleware(['ngwe.auth', 'role:owner']);

Route::get('/cashier/status', fn () => ['role' => 'cashier'])
    ->middleware(['ngwe.auth', 'role:cashier']);

Route::get('/employee/status', fn () => ['role' => 'employee'])
    ->middleware(['ngwe.auth', 'role:employee']);

Route::middleware('ngwe.auth')->group(function (): void {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::get('/service-types', [ServiceTypeController::class, 'index']);
    Route::get('/service-types/{serviceType}', [ServiceTypeController::class, 'show']);
    Route::get('/accounts', [AccountController::class, 'index']);
    Route::get('/accounts/{account}', [AccountController::class, 'show']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/recent', [TransactionController::class, 'recent']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
    Route::post('/transactions/cash-in', [TransactionController::class, 'cashIn']);
    Route::post('/transactions/cash-out', [TransactionController::class, 'cashOut']);
    Route::post('/transactions/transfer', [TransactionController::class, 'transfer']);
    Route::post('/transactions/exchange', [TransactionController::class, 'exchange']);

    Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
    Route::get('/exchange-rates/latest', [ExchangeRateController::class, 'latest']);
    Route::get('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'show']);

    Route::get('/cash-floats', [CashFloatController::class, 'index']);
    Route::get('/cash-floats/{float}', [CashFloatController::class, 'show']);

    Route::get('/vault/balance', [VaultController::class, 'balance']);
    Route::get('/vault/inventory', [VaultController::class, 'inventory']);
});

Route::middleware(['ngwe.auth', 'role:employee'])->group(function (): void {
    Route::post('/cash-floats/{float}/activate', [CashFloatController::class, 'activate']);
    Route::post('/cash-floats/{float}/initiate-return', [CashFloatController::class, 'initiateReturn']);
});

Route::middleware(['ngwe.auth', 'role:cashier,owner'])->group(function (): void {
    Route::post('/cash-floats', [CashFloatController::class, 'store']);
    Route::post('/cash-floats/{float}/confirm-return', [CashFloatController::class, 'confirmReturn']);
});

Route::middleware(['ngwe.auth', 'role:cashier,owner'])->group(function (): void {
    Route::post('/transactions/{transaction}/confirm-cash-in', [TransactionController::class, 'confirmCashIn']);
    Route::post('/transactions/{transaction}/cancel-cash-in', [TransactionController::class, 'cancelCashIn']);
});

Route::middleware(['ngwe.auth', 'role:owner'])->group(function (): void {
    Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy']);
});

Route::middleware(['ngwe.auth', 'role:owner'])->group(function (): void {
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::patch('/companies/{company}', [CompanyController::class, 'update']);
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy']);

    Route::post('/service-types', [ServiceTypeController::class, 'store']);
    Route::patch('/service-types/{serviceType}', [ServiceTypeController::class, 'update']);
    Route::delete('/service-types/{serviceType}', [ServiceTypeController::class, 'destroy']);

    Route::post('/accounts', [AccountController::class, 'store']);
    Route::patch('/accounts/{account}', [AccountController::class, 'update']);
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy']);
    Route::post('/accounts/{account}/balance-adjust', [AccountController::class, 'adjustBalance']);

    Route::post('/exchange-rates', [ExchangeRateController::class, 'store']);
    Route::patch('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'update']);
    Route::delete('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'destroy']);

    Route::post('/broadcast/test', [RealtimeBroadcastController::class, 'test']);
});
