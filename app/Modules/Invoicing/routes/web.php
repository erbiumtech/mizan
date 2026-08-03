<?php

use App\Modules\Invoicing\Http\Controllers\InvoicePdfController;
use Illuminate\Support\Facades\Route;

/**
 * A direct URL never consults canAccess(), so the licence gate is on the route —
 * and the company is in the path because an invoice lives in the tenant database
 * and nothing outside the panel makes one current. See ResolveCompanyFromRoute.
 *
 * The invoice is a plain id rather than a bound model: binding would run before
 * the company is current and look for the invoice in the landlord database.
 *
 * The handler was named as [InvoicePdfController::class, 'show'], a method that
 * has never existed on it — the controller is invokable — so this route answered
 * with a 500 for anyone who reached it. Nothing links here yet, which is why that
 * went unnoticed.
 */
Route::middleware(['web', 'auth', 'company', 'module:invoicing'])
    ->get('/reports/{company}/invoice/{invoice}/pdf', InvoicePdfController::class)
    ->whereNumber('invoice')
    ->name('invoice.pdf');
