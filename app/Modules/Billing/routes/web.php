<?php

use App\Modules\Billing\Http\Controllers\BillingStatementController;
use Illuminate\Support\Facades\Route;

/**
 * The statement page and its PDF. Outside the panel, so it carries its own
 * tenant (`company:`) and its own licence gate (`module:`) — a direct URL
 * consults neither the panel's tenancy nor any canAccess().
 */
Route::middleware(['web', 'auth', 'company', 'module:billing'])
    ->get('/billing/{company}/statement/{run}', BillingStatementController::class)
    ->whereNumber('run')
    ->name('billing.statement');
