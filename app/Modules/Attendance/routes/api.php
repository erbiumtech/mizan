<?php

use App\Modules\Attendance\Http\Controllers\AttendanceController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

// The same stack as Leave's /my-leave-requests: Sanctum caller, company resolved
// from their membership, the surface gated on the module being licensed.
ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:attendance'], function () {
    Route::post('/my-attendance/clock', [AttendanceController::class, 'clock']);
});
