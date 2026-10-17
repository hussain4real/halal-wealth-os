<?php

use App\Http\Controllers\OwnerProfileController;
use App\Http\Middleware\RequireProductionTwoFactor;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified', RequireProductionTwoFactor::class])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::get('owners/{owner}', [OwnerProfileController::class, 'show'])->whereNumber('owner')->name('owners.show');
    Route::patch('owners/{owner}', [OwnerProfileController::class, 'update'])->whereNumber('owner')->name('owners.update');
});

require __DIR__.'/settings.php';
