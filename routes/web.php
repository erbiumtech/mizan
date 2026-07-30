<?php

use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\ReportPageController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\TenantFileController;
use App\Http\Middleware\ResolveStatusPageTenant;
use App\Support\TenantStorage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(Filament::getPanel('admin')->getUrl());
});

// Public status page: unauthenticated, off unless a company enables it, and
// reachable only with the token from Company Settings. The middleware resolves
// and then forgets the tenant, so nothing here leaks into the panel session.
// The Projects module is a *second* condition here, not a replacement for the
// per-company status_page.enabled setting: both must be true. 404 rather than
// 403 to match the middleware above — an unlisted page should not confirm it
// exists. Ordered after ResolveStatusPageTenant, which makes the company
// current, so the licence check knows whose licence to read.
Route::get('/status/{company}/{token}', [StatusPageController::class, 'show'])
    ->middleware([ResolveStatusPageTenant::class, 'module:projects,404'])
    ->name('status.show');

// Stored files (payslip/MPR PDFs, NIC scans, receipts) are streamed through the
// app rather than served off a `public/storage` symlink: the symlink cannot be
// created on every host, and it would expose one company's files to another.
// Accepts a panel session or a Sanctum token so API `pdf_url`s work too.
// Bound by id, not the model's `slug` route key: the on-disk directory is
// `tenants/{id}`, and keying the URL the same way keeps the two from drifting.
Route::get(TenantStorage::URL_PREFIX.'/{company:id}/{path}', [TenantFileController::class, 'show'])
    ->where('path', '.*')
    ->middleware(['auth:web,sanctum'])
    ->name('tenant-file');

// These bypass every canAccess() check, which is exactly why they are gated here:
// the report pages are Accounting and the invoice PDF is Invoicing, so they are
// grouped separately rather than sharing one `module:` parameter.
Route::middleware(['auth'])->prefix('reports')->group(function () {
    Route::middleware('module:accounting')->group(function () {
        Route::get('/trial-balance', [ReportPageController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('/profit-and-loss', [ReportPageController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
    });

    Route::get('/invoice/{invoice}/pdf', [InvoicePdfController::class, 'show'])
        ->middleware('module:invoicing')
        ->name('invoice.pdf');
});
