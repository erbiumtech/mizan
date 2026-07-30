<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\MprController;
use App\Http\Controllers\Api\PayslipController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\AuthController;


Route::post('/login', [AuthController::class, 'login']);

// Every group below is gated on its module: a company that has not licensed
// Payroll has no /my-payslips, rather than an empty one. Mobile clients need to
// read 403 as "not available for this company" — these endpoints used to always
// answer, so check the shipped builds before revoking a licence in production.
Route::middleware('auth:sanctum')->group(function () {

    // Employee Profile Route
    Route::middleware('module:employees')->group(function () {
        Route::get('/my-profile', [EmployeeController::class, 'myProfile']);
    });

    // MPR Route
    Route::middleware('module:mpr')->group(function () {
        Route::get('/my-mprs', [MprController::class, 'index']);
        Route::get('/my-mprs/comparison', [MprController::class, 'comparison']);
        Route::get('/my-mprs/{id}', [MprController::class, 'show']);
    });

    // Payslips Route
    Route::middleware('module:payroll')->group(function () {
        Route::get('/my-payslips', [PayslipController::class, 'index']);
    });

    Route::middleware('module:accounting')->group(function () {
        // Financial Reports
        Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
        Route::get('/reports/profit-and-loss', [ReportController::class, 'profitAndLoss']);

        // Chart of Accounts
        Route::get('/accounts/tree', [AccountController::class, 'tree']);
        Route::apiResource('accounts', AccountController::class);
    });

});
