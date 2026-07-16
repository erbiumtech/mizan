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
});
