<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\OwnerProfileController;
use App\Http\Controllers\TransactionController;
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
    Route::post('accounts/{account}/opening', [TransactionController::class, 'postOpening'])->whereNumber('account')->name('accounts.post-opening');
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/new', [TransactionController::class, 'create'])->name('transactions.create');
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('transactions/{journal}', [TransactionController::class, 'show'])->whereNumber('journal')->name('transactions.show');
    Route::get('transactions/{journal}/correct', [TransactionController::class, 'correction'])->whereNumber('journal')->name('transactions.correction');
    Route::post('transactions/{journal}/correct', [TransactionController::class, 'correct'])->whereNumber('journal')->name('transactions.correct');
    Route::post('transactions/{journal}/reverse', [TransactionController::class, 'reverse'])->whereNumber('journal')->name('transactions.reverse');
});

Route::middleware(['auth'])->group(function () {
    Route::get('owners/{owner}', [OwnerProfileController::class, 'show'])->whereNumber('owner')->name('owners.show');
    Route::patch('owners/{owner}', [OwnerProfileController::class, 'update'])->whereNumber('owner')->name('owners.update');
});

require __DIR__.'/settings.php';
