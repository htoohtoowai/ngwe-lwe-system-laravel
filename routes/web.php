<?php

use App\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::inertia('/login', 'Login')->name('login');
Route::inertia('/', 'Welcome')->name('home');

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
    });
