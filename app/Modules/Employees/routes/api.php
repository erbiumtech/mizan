<?php

use App\Modules\Employees\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['api', 'auth:sanctum', 'module:employees'])
    ->group(function () {
        Route::get('/my-profile', [EmployeeController::class, 'myProfile']);
    });
