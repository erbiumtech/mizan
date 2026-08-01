<?php

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

// The report pages live in app/Modules/Accounting/routes/web.php and the invoice
// PDF in app/Modules/Invoicing/routes/web.php.

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
