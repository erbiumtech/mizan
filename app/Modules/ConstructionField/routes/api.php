<?php

use App\Http\Controllers\Api\SyncController;
use App\Modules\ConstructionField\Sync\PunchItemWorkflow;
use App\Modules\ConstructionField\Sync\SiteDiaryWorkflow;
use App\Support\ApiRoutes;
use Illuminate\Support\Facades\Route;

/*
 * Site work captured offline, and the reads that keep a device current — docs/desktop-mobile-plan.md O2.
 * On /v1 only (D4), under this module's own gate, with `workflow` fixed per route rather than a free
 * segment so the licence that gates a diary line is the site operations module's, as the attendance
 * routes do for a punch. The inbox, the blob queue and the read are the engine's (App\Support\Sync).
 */
ApiRoutes::versioned(['api', 'auth:sanctum', 'api.company', 'module:construction_field'], function () {
    Route::post('/sync/site-diary', [SyncController::class, 'receive'])->defaults('workflow', SiteDiaryWorkflow::KEY);
    Route::post('/sync/site-diary/blobs', [SyncController::class, 'blob'])->defaults('workflow', SiteDiaryWorkflow::KEY);
    Route::get('/sync/site-diary/changes', [SyncController::class, 'changes'])->defaults('workflow', SiteDiaryWorkflow::KEY);

    Route::post('/sync/punch-items', [SyncController::class, 'receive'])->defaults('workflow', PunchItemWorkflow::KEY);
    Route::get('/sync/punch-items/changes', [SyncController::class, 'changes'])->defaults('workflow', PunchItemWorkflow::KEY);
});
