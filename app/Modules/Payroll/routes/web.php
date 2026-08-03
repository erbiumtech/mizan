<?php

use App\Modules\Payroll\Http\Controllers\PayslipMediaController;
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
