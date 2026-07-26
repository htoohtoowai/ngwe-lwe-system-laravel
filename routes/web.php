<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TellerController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\TransactionEntryController;
use Illuminate\Support\Facades\Route;

Route::get('/login', LoginController::class)->name('login');
Route::inertia('/', 'RootRedirect')->name('home');
Route::get('/reports/daily/pdf', fn () => response('Daily report PDF is not implemented yet.', 501))
    ->name('reports.daily.pdf');
Route::middleware('ngwe.auth')->get('/dashboard', DashboardController::class)->name('dashboard');
Route::middleware(['ngwe.auth', 'role:cashier'])->get('/cashier', CashierController::class)->name('cashier');
Route::middleware(['ngwe.auth', 'role:cashier'])->get('/cashier/profile', [CashierController::class, 'profile'])->name('cashier.profile');
Route::middleware(['ngwe.auth', 'role:teller'])
    ->prefix('transactions')
    ->name('transactions.')
    ->controller(TransactionEntryController::class)
    ->group(function (): void {
        Route::redirect('/', '/transactions/transfer')->name('index');
        Route::get('/cash-in', 'cashIn')->name('cash-in');
        Route::get('/cash-out', 'cashOut')->name('cash-out');
        Route::get('/transfer', 'transfer')->name('transfer');
        Route::get('/exchange', 'exchange')->name('exchange');
    });

Route::middleware(['ngwe.auth', 'role:teller'])
    ->prefix('transactions')
    ->name('transactions.')
    ->controller(TransactionEntryController::class)
    ->group(function (): void {
        Route::post('/cash-in', 'cashInStore')->name('cash-in.store');
        Route::post('/cash-out', 'cashOutStore')->name('cash-out.store');
        Route::post('/transfer', 'transferStore')->name('transfer.store');
        Route::post('/exchange', 'exchangeStore')->name('exchange.store');
    });

Route::middleware(['ngwe.auth', 'role:teller'])
    ->prefix('teller')
    ->name('teller.')
    ->controller(TellerController::class)
    ->group(function (): void {
        Route::get('/', 'counter')->name('counter');
        Route::redirect('/cash-in', '/transactions/cash-in')->name('cash-in');
        Route::redirect('/cash-out', '/transactions/cash-out')->name('cash-out');
        Route::redirect('/transfer', '/transactions/transfer')->name('transfer');
        Route::redirect('/exchange', '/transactions/exchange')->name('exchange');
        Route::get('/float', 'floatPage')->name('float');
        Route::post('/transactions/cash-in', 'cashInStore')->name('transactions.cash-in');
        Route::post('/transactions/cash-out', 'cashOutStore')->name('transactions.cash-out');
        Route::post('/transactions/transfer', 'transferStore')->name('transactions.transfer');
        Route::post('/transactions/exchange', 'exchangeStore')->name('transactions.exchange');
    });
