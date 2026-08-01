<?php

use App\Modules\Invoicing\Http\Controllers\InvoicePdfController;
use Illuminate\Support\Facades\Route;

// A direct URL never consults canAccess(), so the licence gate is on the route.
Route::middleware(['web', 'auth', 'module:invoicing'])
    ->get('/reports/invoice/{invoice}/pdf', [InvoicePdfController::class, 'show'])
    ->name('invoice.pdf');
