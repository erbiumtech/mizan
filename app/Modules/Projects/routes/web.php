<?php

use App\Modules\Projects\Http\Controllers\StatusPageController;
use App\Modules\Projects\Http\Middleware\ResolveStatusPageTenant;
use Illuminate\Support\Facades\Route;

/**
 * Public status page: unauthenticated, off unless a company enables it, and
 * reachable only with the token from Company Settings. The middleware resolves
 * and then forgets the tenant, so nothing here leaks into the panel session.
 *
 * The Projects module is a *second* condition, not a replacement for the
 * per-company status_page.enabled setting: both must be true. 404 rather than
 * 403 to match the middleware — an unlisted page should not confirm it exists.
 * Ordered after ResolveStatusPageTenant, which makes the company current, so the
 * licence check knows whose licence to read.
 */
Route::middleware('web')->group(function () {
    Route::get('/status/{company}/{token}', [StatusPageController::class, 'show'])
        ->middleware([ResolveStatusPageTenant::class, 'module:projects,404'])
        ->name('status.show');
});
