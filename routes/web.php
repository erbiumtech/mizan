<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/nova');
});

Route::middleware(['auth'])->prefix('reports')->group(function () {
    Route::get('/trial-balance', [\App\Http\Controllers\ReportPageController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/profit-and-loss', [\App\Http\Controllers\ReportPageController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
    Route::get('/salary-bank-file', \App\Http\Controllers\SalaryBankExportController::class)->name('reports.salary-bank-file');
    Route::get('/bank-payment-file', \App\Http\Controllers\BankPaymentFileController::class)->name('reports.bank-payment-file');
    Route::get('/register/{account?}', [\App\Http\Controllers\AccountRegisterController::class, 'show'])->name('register.show');
    Route::post('/register/{account}', [\App\Http\Controllers\AccountRegisterController::class, 'store'])->name('register.store');
    Route::get('/gnucash-import', [\App\Http\Controllers\GnuCashImportController::class, 'show'])->name('gnucash.show');
    Route::post('/gnucash-import/preview', [\App\Http\Controllers\GnuCashImportController::class, 'preview'])->name('gnucash.preview');
    Route::post('/gnucash-import/confirm', [\App\Http\Controllers\GnuCashImportController::class, 'confirm'])->name('gnucash.confirm');
    Route::get('/petty-cash', [\App\Http\Controllers\PettyCashController::class, 'show'])->name('petty-cash.show');
    Route::post('/petty-cash/voucher', [\App\Http\Controllers\PettyCashController::class, 'storeVoucher'])->name('petty-cash.voucher');
    Route::post('/petty-cash/topup', [\App\Http\Controllers\PettyCashController::class, 'topUp'])->name('petty-cash.topup');
    Route::post('/petty-cash/replenish', [\App\Http\Controllers\PettyCashController::class, 'replenish'])->name('petty-cash.replenish');
    Route::get('/invoice/{invoice}/pdf', \App\Http\Controllers\InvoicePdfController::class)->name('invoice.pdf');
});
