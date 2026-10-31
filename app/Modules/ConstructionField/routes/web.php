<?php

use App\Http\Controllers\Api\SyncController;
use App\Modules\ConstructionField\Sync\PunchItemWorkflow;
use App\Modules\ConstructionField\Sync\SiteDiaryWorkflow;
use Illuminate\Support\Facades\Route;

/*
 * The panel's own way into the site sync inbox — docs/desktop-mobile-plan.md O2, the same arrangement
 * Attendance makes for a punch. The offline page that captures a diary line has the person's session and
 * no bearer token, so its outbox replays through the session, with the CSRF token the panel left it, to
 * the controller the API mounts under /v1. `{company}` names which company the line belongs to.
 */
Route::middleware(['web', 'auth', 'company', 'module:construction_field'])
    ->prefix('{company}')
    ->name('construction_field.sync.')
    ->group(function (): void {
        Route::post('/sync/site-diary', [SyncController::class, 'receive'])
            ->defaults('workflow', SiteDiaryWorkflow::KEY)
            ->name('site-diary');

        Route::post('/sync/site-diary/blobs', [SyncController::class, 'blob'])
            ->defaults('workflow', SiteDiaryWorkflow::KEY)
            ->name('site-diary.blobs');

        Route::post('/sync/punch-items', [SyncController::class, 'receive'])
            ->defaults('workflow', PunchItemWorkflow::KEY)
            ->name('punch-items');
    });
