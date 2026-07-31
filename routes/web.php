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
Route::get('/status/{company}/{token}', [StatusPageController::class, 'show'])
    ->middleware(ResolveStatusPageTenant::class)
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

Route::middleware(['auth'])->prefix('reports')->group(function () {
    Route::get('/trial-balance', [ReportPageController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/profit-and-loss', [ReportPageController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
    Route::get('/invoice/{invoice}/pdf', [InvoicePdfController::class, 'show'])->name('invoice.pdf');
});

// Returning from impersonation. A plain route rather than a Livewire action so
// the way back works from any page in the panel, including one that fails to
// render — being stuck as somebody else is the failure mode to avoid.
Route::post('/impersonate/stop', function () {
    $impersonator = app(App\Support\Impersonation::class)->stop();

    if (! $impersonator) {
        return redirect()->back();
    }

    \Filament\Notifications\Notification::make()
        ->success()
        ->title('Welcome back, '.$impersonator->name)
        ->send();

    return redirect(\Filament\Facades\Filament::getPanel('admin')->getUrl());
})->middleware(['web', 'auth'])->name('impersonate.stop');
