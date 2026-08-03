<?php

use App\Modules\Payroll\Http\Controllers\PayslipController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['api', 'auth:sanctum', 'module:payroll'])
    ->group(function () {
        Route::get('/my-payslips', [PayslipController::class, 'index']);

        // Rendered on request, never served from disk, so the PDF always matches
        // the payslip as it stands. Named, because index() builds the URL.
        Route::get('/my-payslips/{payslip}/pdf', [PayslipController::class, 'pdf'])
            ->whereNumber('payslip')
            ->name('payslips.pdf');
    });
