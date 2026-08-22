<?php

use App\Modules\Crm\Http\Controllers\LeadCaptureController;
use App\Modules\Crm\Http\Middleware\ResolveLeadCaptureTenant;
use Illuminate\Support\Facades\Route;

/**
 * Public lead capture: unauthenticated, off unless a company enables it, and reachable only
 * with the token from Company Settings.
 *
 * The exact shape of the status page in Projects, which §3 cites as the precedent — the same
 * two gates, the same 404-not-403, and the same middleware that resolves the tenant and then
 * forgets it so nothing leaks into a panel session.
 *
 * `module:crm,404` is a THIRD condition and not a replacement for the per-company setting:
 * all three must hold. Ordered after the tenant middleware, so the licence check knows whose
 * licence to read.
 *
 * Deliberately outside `web`'s CSRF: a form on somebody else's website cannot hold a token
 * from this application's session. The token in the URL is the credential, which is why it
 * is gated twice and throttled.
 */
Route::post('/leads/{company}/{token}', [LeadCaptureController::class, 'store'])
    ->middleware([ResolveLeadCaptureTenant::class, 'module:crm,404'])
    ->name('crm.leads.capture');
