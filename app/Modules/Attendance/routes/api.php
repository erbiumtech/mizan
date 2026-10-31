<?php

use App\Http\Controllers\Api\SyncController;
use App\Modules\Attendance\Http\Controllers\AttendanceController;
use App\Modules\Attendance\Sync\AttendancePunchWorkflow;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

// The same stack as Leave's /my-leave-requests: Sanctum caller, company resolved
// from their membership, the surface gated on the module being licensed.
ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:attendance'], function () {
    Route::post('/my-attendance/clock', [AttendanceController::class, 'clock']);
});

// Punches made offline, and the read that keeps a device current — docs/desktop-mobile-plan.md O1. On
// /v1 only (D4). The inbox is generic (App\Support\Sync); the routes live here so the gate on a punch is
// this module's licence, and `workflow` is fixed rather than a free segment for the same reason.
ApiRoutes::versioned(['api', 'auth:sanctum', 'api.company', 'module:attendance'], function () {
    Route::post('/sync/attendance', [SyncController::class, 'receive'])->defaults('workflow', AttendancePunchWorkflow::KEY);
    Route::get('/sync/attendance/changes', [SyncController::class, 'changes'])->defaults('workflow', AttendancePunchWorkflow::KEY);
});
