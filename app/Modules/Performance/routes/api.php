<?php

use App\Modules\Performance\Http\Controllers\DailyReportController;
use App\Modules\Performance\Http\Controllers\TaskController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

// The same stack as Leave's /my-leave-requests: Sanctum caller, company resolved
// from their membership, the surface gated on the module being licensed.
ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:performance'], function () {
    Route::get('/my-tasks', [TaskController::class, 'index']);
    Route::post('/my-tasks/{occurrence}/complete', [TaskController::class, 'complete'])->whereNumber('occurrence');
    Route::get('/my-daily-reports', [DailyReportController::class, 'index']);
    Route::post('/my-daily-reports', [DailyReportController::class, 'store']);
});
