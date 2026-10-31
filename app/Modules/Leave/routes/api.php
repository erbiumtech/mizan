<?php

use App\Modules\Leave\Http\Controllers\LeaveApprovalController;
use App\Modules\Leave\Http\Controllers\LeaveController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

// The same stack as Payroll's /my-payslips: Sanctum caller, company resolved from
// their membership, and the whole surface gated on the module being licensed.
ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:leave'], function () {
    Route::get('/my-leave-balances', [LeaveController::class, 'balances']);
    Route::post('/my-leave-requests', [LeaveController::class, 'apply']);
});

// A manager's approvals — docs/desktop-mobile-plan.md A4/M3. New work lands on /v1 only (D4).
ApiRoutes::versioned(['api', 'auth:sanctum', 'api.company', 'module:leave'], function () {
    Route::get('/approvals/leave', [LeaveApprovalController::class, 'index']);
    Route::post('/approvals/leave/{leaveRequest}/approve', [LeaveApprovalController::class, 'approve'])->whereNumber('leaveRequest');
    Route::post('/approvals/leave/{leaveRequest}/refuse', [LeaveApprovalController::class, 'refuse'])->whereNumber('leaveRequest');
});
