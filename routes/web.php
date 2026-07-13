<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/login', LoginController::class)->name('login');
Route::inertia('/', 'Welcome')->name('home');
Route::get('/reports/daily/pdf', fn () => response('Daily report PDF is not implemented yet.', 501))
    ->name('reports.daily.pdf');
Route::middleware('ngwe.auth')->get('/dashboard', DashboardController::class)->name('dashboard');

Route::middleware(['ngwe.auth', 'role:employee'])
    ->prefix('employee')
    ->name('employee.')
    ->controller(EmployeeController::class)
    ->group(function (): void {
        Route::get('/', 'counter')->name('counter');
        Route::get('/cash-in', 'cashIn')->name('cash-in');
        Route::get('/cash-out', 'cashOut')->name('cash-out');
        Route::get('/transfer', 'transfer')->name('transfer');
        Route::get('/exchange', 'exchange')->name('exchange');
        Route::get('/float', 'floatPage')->name('float');
        Route::post('/transactions/cash-in', 'cashInStore')->name('transactions.cash-in');
        Route::post('/transactions/cash-out', 'cashOutStore')->name('transactions.cash-out');
        Route::post('/transactions/transfer', 'transferStore')->name('transactions.transfer');
        Route::post('/transactions/exchange', 'exchangeStore')->name('transactions.exchange');
    });
