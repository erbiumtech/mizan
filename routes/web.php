<?php

use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\TenantFileController;
use App\Support\TenantStorage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(Filament::getPanel('admin')->getUrl());
});

// The public status page lives in app/Modules/Projects/routes/web.php.

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

// The report pages live in app/Modules/Accounting/routes/web.php.
Route::get('/reports/invoice/{invoice}/pdf', [InvoicePdfController::class, 'show'])
    ->middleware(['auth', 'module:invoicing'])
    ->name('invoice.pdf');
