<?php

use App\Modules\Work\Http\Controllers\InboundHookController;
use App\Modules\Work\Http\Middleware\ResolveHookTenant;
use Illuminate\Support\Facades\Route;

/**
 * Inbound hooks from the code hosts — docs/project-management-plan.md §8.3.
 *
 * Public by design and outside `web`'s CSRF: GitHub cannot hold a token from this application's
 * session. The credential is the signature on the body, checked against the connection's secret
 * before anything is written. The tenant middleware resolves the company from the slug and the
 * connection from its id, and answers 404 to everything that is not exactly right.
 */
Route::post('/hooks/{provider}/{company}/{connection}', [InboundHookController::class, 'store'])
    ->middleware([ResolveHookTenant::class])
    ->whereNumber('connection')
    ->where('provider', '[a-z_]+')
    ->name('work.hooks.inbound');
