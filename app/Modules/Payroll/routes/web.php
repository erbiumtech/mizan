<?php

use App\Modules\Payroll\Http\Controllers\PayslipMediaController;
use App\Modules\Payroll\Http\Controllers\TaxSummaryReportController;
use Illuminate\Support\Facades\Route;

/**
 * The payslip PDF for a WhatsApp provider to collect, when media is sent by link.
 *
 * Every part of the link is a path segment, expiry and signature included — see
 * PayslipMediaLink for why it cannot be a query string. There is no session on
 * this request (Twilio is the reader), so the signature is the whole of the
 * authorization; the controller checks it before anything is read.
 *
 * No `module:payroll` either: that middleware asks a question about the signed-in
 * user's company, and there is no signed-in user.
 */
Route::middleware(['web'])
    ->get('/whatsapp-media/{company}/payslip/{payslip}/{expires}/{signature}/{filename}', PayslipMediaController::class)
    ->whereNumber('payslip')
    ->whereNumber('expires')
    ->where('signature', '[a-f0-9]{64}')
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('payslip.whatsapp-media');

/**
 * The printable tax summary. Payroll's rather than Accounting's because the licence
 * gate differs: withholding is payroll's, and a company that keeps its books here
 * without running payroll should not see it.
 *
 * The company is a path segment because payslips live in the tenant database and
 * nothing outside the panel makes one current. See ResolveCompanyFromRoute.
 */
Route::middleware(['web', 'auth', 'company', 'module:payroll'])
    ->get('/reports/{company}/tax-summary', TaxSummaryReportController::class)
    ->name('reports.tax-summary');
