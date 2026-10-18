<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\OwnerProfileController;
use App\Http\Middleware\RequireProductionTwoFactor;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified', RequireProductionTwoFactor::class])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->whereNumber('account')->name('accounts.show');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->whereNumber('account')->name('accounts.update');
    Route::patch('accounts/{account}/archive', [AccountController::class, 'archive'])->whereNumber('account')->name('accounts.archive');
});

Route::middleware(['auth'])->group(function () {
    Route::get('owners/{owner}', [OwnerProfileController::class, 'show'])->whereNumber('owner')->name('owners.show');
    Route::patch('owners/{owner}', [OwnerProfileController::class, 'update'])->whereNumber('owner')->name('owners.update');
});

require __DIR__.'/settings.php';
