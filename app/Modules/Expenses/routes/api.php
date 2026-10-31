<?php

use App\Modules\Expenses\Http\Controllers\ExpenseClaimApprovalController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

// A manager's approvals — docs/desktop-mobile-plan.md A4/M3. The same stack as every module API (a Sanctum
// caller, the company from their membership, the module licensed), on the versioned surface only (D4).
ApiRoutes::versioned(['api', 'auth:sanctum', 'api.company', 'module:expenses'], function () {
    Route::get('/approvals/expense-claims', [ExpenseClaimApprovalController::class, 'index']);
    Route::post('/approvals/expense-claims/{expenseClaim}/approve', [ExpenseClaimApprovalController::class, 'approve'])->whereNumber('expenseClaim');
    Route::post('/approvals/expense-claims/{expenseClaim}/refuse', [ExpenseClaimApprovalController::class, 'refuse'])->whereNumber('expenseClaim');
});
