<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/login', 'Login')->name('login');
Route::inertia('/', 'Welcome')->name('home');
