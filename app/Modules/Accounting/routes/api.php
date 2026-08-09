<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['api', 'auth:sanctum', 'module:accounting'])
    ->group(function () {
        Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
        Route::get('/reports/profit-and-loss', [ReportController::class, 'profitAndLoss']);

        Route::get('/accounts/tree', [AccountController::class, 'tree']);
        Route::apiResource('accounts', AccountController::class);
    });
