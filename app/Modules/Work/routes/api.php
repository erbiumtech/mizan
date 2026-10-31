<?php

use App\Modules\Work\Http\Controllers\Api\WorkApiController;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/**
 * The work API — docs/project-management-plan.md §11.
 *
 * Mounted on both API surfaces by `ApiRoutes`, behind the module gate and the `work` ability a key
 * has to be issued with: a token that may read payslips may not read a board. Every route is in
 * `docs/api/openapi.yaml`, and `ApiContractTest` holds the two to each other.
 */
// Sanctum's ability check by class: this application registers no `abilities` alias, and a class with
// its parameter is the one spelling that cannot drift from the alias list.
ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:work', CheckAbilities::class.':work'], function (): void {
    Route::get('/work/projects', [WorkApiController::class, 'projects']);
    Route::get('/work/projects/{key}/board', [WorkApiController::class, 'board'])->where('key', '[A-Za-z0-9]+');

    Route::get('/work/items', [WorkApiController::class, 'index']);
    Route::post('/work/items', [WorkApiController::class, 'store']);
    Route::get('/work/items/{key}', [WorkApiController::class, 'show'])->where('key', '[A-Za-z0-9]+-\d+');
    Route::patch('/work/items/{key}', [WorkApiController::class, 'update'])->where('key', '[A-Za-z0-9]+-\d+');
    Route::post('/work/items/{key}/move', [WorkApiController::class, 'move'])->where('key', '[A-Za-z0-9]+-\d+');
    Route::post('/work/items/{key}/comments', [WorkApiController::class, 'comment'])->where('key', '[A-Za-z0-9]+-\d+');
    Route::post('/work/items/{key}/time', [WorkApiController::class, 'time'])->where('key', '[A-Za-z0-9]+-\d+');

    Route::get('/work/my', [WorkApiController::class, 'my']);
});
