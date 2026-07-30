<?php

use App\Modules\Payroll\Http\Controllers\PayslipController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['api', 'auth:sanctum', 'module:payroll'])
    ->group(function () {
        Route::get('/my-payslips', [PayslipController::class, 'index']);
    });
