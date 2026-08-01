<?php

use App\Modules\Accounting\Http\Controllers\ReportPageController;
use Illuminate\Support\Facades\Route;

/**
 * The report pages bypass every canAccess() check — a direct URL never consults
 * one — which is why the licence gate is on the route itself.
 */
Route::middleware(['web', 'auth', 'module:accounting'])
    ->prefix('reports')
    ->group(function () {
        Route::get('/trial-balance', [ReportPageController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('/profit-and-loss', [ReportPageController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
    });
