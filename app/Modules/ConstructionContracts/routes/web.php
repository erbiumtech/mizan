<?php

use App\Modules\ConstructionContracts\Http\Controllers\CertificatePdfController;
use Illuminate\Support\Facades\Route;

/**
 * A direct URL never consults `canAccess()`, so the licence gate is on the route — and the company is in the path
 * because a certificate lives in the tenant database and nothing outside the panel makes one current. See
 * `ResolveCompanyFromRoute`, and `Invoicing/routes/web.php`, which is the shape this copies.
 *
 * The certificate is a plain id rather than a bound model: binding would run before the company is current and
 * look for it in the landlord database.
 */
Route::middleware(['web', 'auth', 'company', 'module:construction_contracts'])
    ->group(function () {
        Route::get('/reports/{company}/construction-certificate/{certificate}/pdf', CertificatePdfController::class)
            ->whereNumber('certificate')
            ->name('construction.certificate.pdf');
    });
