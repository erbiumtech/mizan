<?php

use App\Modules\Crm\Http\Controllers\FieldVisitController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

// The phone's side of a field visit — docs/saas-launch-plan.md §8.5. The same stack as
// /my-attendance/clock: Sanctum caller, company from their membership, module licensed.
ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:crm'], function () {
    Route::get('/my-field-visits', [FieldVisitController::class, 'index']);
    Route::post('/field-visits/{visit}/check-in', [FieldVisitController::class, 'checkIn'])->whereNumber('visit');
    Route::post('/field-visits/{visit}/check-out', [FieldVisitController::class, 'checkOut'])->whereNumber('visit');
});
