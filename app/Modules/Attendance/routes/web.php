<?php

use App\Http\Controllers\Api\SyncController;
use App\Modules\Attendance\Sync\AttendancePunchWorkflow;
use Illuminate\Support\Facades\Route;

/*
 * The panel's own way into the sync inbox — docs/desktop-mobile-plan.md O1. The offline page that
 * captures a punch has the person's session and no bearer token, so the outbox it replays through the
 * session, with the CSRF token the panel left it, to the same controller the API mounts under /v1.
 * `{company}` names which company the punch belongs to, as every page outside the panel does.
 */
Route::middleware(['web', 'auth', 'company', 'module:attendance'])
    ->prefix('{company}')
    ->name('attendance.')
    ->group(function (): void {
        Route::post('/sync/attendance', [SyncController::class, 'receive'])
            ->defaults('workflow', AttendancePunchWorkflow::KEY)
            ->name('sync');
    });
