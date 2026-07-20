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

Route::middleware('auth:sanctum')->group(function () {

    // Employee Profile Route
    Route::get('/my-profile', [EmployeeController::class, 'myProfile']);

    // MPR Route
    Route::get('/my-mprs', [MprController::class, 'index']);
    Route::get('/my-mprs/comparison', [MprController::class, 'comparison']);
    Route::get('/my-mprs/{id}', [MprController::class, 'show']);

    // Payslips Route
    Route::get('/my-payslips', [PayslipController::class, 'index']);

    // Financial Reports
    Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance']);
    Route::get('/reports/profit-and-loss', [ReportController::class, 'profitAndLoss']);

    // Chart of Accounts
    Route::get('/accounts/tree', [AccountController::class, 'tree']);
    Route::apiResource('accounts', AccountController::class);

});
