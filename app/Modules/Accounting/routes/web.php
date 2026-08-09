<?php

use App\Modules\Accounting\Http\Controllers\ReportPageController;
use Illuminate\Support\Facades\Route;

/**
 * The report pages bypass every canAccess() check — a direct URL never consults
 * one — which is why the licence gate is on the route itself.
 *
 * The company is in the path for the same reason. These pages live outside the
 * panel, so nothing makes a tenant current for them, and a trial balance is read
 * entirely from the tenant database — without `company:` they ran against the
 * landlord connection, which holds no accounts at all. It comes before `module:`
 * because which modules are licensed is a question about a company, and there has
 * to be one current to answer it.
 */
Route::middleware(['web', 'auth', 'company', 'module:accounting'])
    ->prefix('reports/{company}')
    ->group(function () {
        Route::get('/cash-flow', [ReportPageController::class, 'cashFlow'])->name('reports.cash-flow');
        Route::get('/balance-sheet', [ReportPageController::class, 'balanceSheet'])->name('reports.balance-sheet');
        Route::get('/trial-balance', [ReportPageController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('/profit-and-loss', [ReportPageController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
    });
