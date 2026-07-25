<?php

use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\ReportPageController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(Filament::getPanel('admin')->getUrl());
});

Route::middleware(['auth'])->prefix('reports')->group(function () {
    Route::get('/trial-balance', [ReportPageController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/profit-and-loss', [ReportPageController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
    Route::get('/invoice/{invoice}/pdf', [InvoicePdfController::class, 'show'])->name('invoice.pdf');
});
