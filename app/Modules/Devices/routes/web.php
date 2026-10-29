<?php

use App\Modules\Devices\Http\Controllers\IclockController;
use App\Modules\Devices\Http\Middleware\ResolveDeviceTenant;
use Illuminate\Support\Facades\Route;

/**
 * The ZKTeco push protocol — docs/saas-launch-plan.md §8.7. Outside `web`: a
 * device holds no session and sends no CSRF token; the registered serial in the
 * query string is the credential, throttled per serial, and the module gate
 * answers 404 like the serial check does.
 */
Route::middleware([ResolveDeviceTenant::class, 'module:devices,404', 'throttle:devices'])->group(function (): void {
    Route::get('/iclock/cdata', [IclockController::class, 'handshake'])->name('devices.handshake');
    Route::post('/iclock/cdata', [IclockController::class, 'receive'])->name('devices.receive');
    Route::get('/iclock/getrequest', [IclockController::class, 'commands'])->name('devices.commands');
});
