<?php

use App\Http\Controllers\Admin\SpendReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\EstimateGenerationCostController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The starter kit's empty dashboard: send people straight to their history instead.
    Route::redirect('dashboard', '/generations')->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/create', [GenerationController::class, 'create'])->name('generations.create');

    // Each submit can cost money and uses our shared Pixelforge rate limit.
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generation-submits')
        ->name('generations.store');

    Route::get('generations/estimate', EstimateGenerationCostController::class)
        ->middleware('throttle:generation-estimates')
        ->name('generations.estimate');

    // Balance, fake "Buy credits" and the credit ledger.
    Route::get('credits', [CreditsController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditsController::class, 'store'])
        ->middleware('throttle:credit-purchases')
        ->name('credits.store');

    // Operators only (users:make-admin). The Form Request authorizes too, so it's checked twice.
    Route::get('admin/report', SpendReportController::class)
        ->middleware('can:view-admin-report')
        ->name('admin.report');
});

require __DIR__.'/settings.php';
