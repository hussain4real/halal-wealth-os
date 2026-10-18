<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\LedgerController;
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
    Route::get('fx', [LedgerController::class, 'fxIndex'])->name('fx.index');
    Route::get('fx/new', [LedgerController::class, 'fxCreate'])->name('fx.create');
    Route::post('fx', [LedgerController::class, 'fxStore'])->name('fx.store');
    Route::get('fx/{operation}', [LedgerController::class, 'fxShow'])->whereNumber('operation')->name('fx.show');
    Route::get('fx/{operation}/correct', [LedgerController::class, 'fxCorrection'])->whereNumber('operation')->name('fx.correction');
    Route::post('fx/{operation}/correct', [LedgerController::class, 'fxStore'])->whereNumber('operation')->name('fx.correct');
    Route::post('fx/{operation}/reverse', [LedgerController::class, 'fxReverse'])->whereNumber('operation')->name('fx.reverse');
    Route::get('rates', [LedgerController::class, 'rates'])->name('rates.index');
    Route::post('rates', [LedgerController::class, 'rateStore'])->name('rates.store');
    Route::get('reports', [LedgerController::class, 'reports'])->name('reports.index');
    Route::post('reports', [LedgerController::class, 'reportStore'])->name('reports.store');
    Route::get('reports/{report}', [LedgerController::class, 'reportShow'])->whereNumber('report')->name('reports.show');
    Route::get('reconciliations', [LedgerController::class, 'reconciliations'])->name('reconciliations.index');
    Route::post('reconciliations', [LedgerController::class, 'reconciliationStore'])->name('reconciliations.store');
    Route::get('reconciliations/{session}', [LedgerController::class, 'reconciliationShow'])->whereNumber('session')->name('reconciliations.show');
    Route::post('reconciliations/{session}/adjust', [LedgerController::class, 'reconciliationAdjust'])->whereNumber('session')->name('reconciliations.adjust');
});

Route::middleware(['auth'])->group(function () {
    Route::get('owners/{owner}', [OwnerProfileController::class, 'show'])->whereNumber('owner')->name('owners.show');
    Route::patch('owners/{owner}', [OwnerProfileController::class, 'update'])->whereNumber('owner')->name('owners.update');
});

require __DIR__.'/settings.php';
