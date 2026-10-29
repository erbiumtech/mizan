<?php

use App\Modules\Recruitment\Http\Controllers\CareersController;
use App\Modules\Recruitment\Http\Middleware\ResolveCareersTenant;
use Illuminate\Support\Facades\Route;

/*
 * The careers page — docs/saas-launch-plan.md §7.4. Public, behind the two gates
 * the middleware checks; the module gate answers 404 like the gates do.
 */
Route::middleware(['web', ResolveCareersTenant::class, 'module:recruitment,404'])->group(function (): void {
    Route::get('/careers/{company}/{token}', [CareersController::class, 'index'])->name('recruitment.careers.index');
    Route::get('/careers/{company}/{token}/{vacancy}', [CareersController::class, 'show'])->whereNumber('vacancy')->name('recruitment.careers.show');
    Route::post('/careers/{company}/{token}/{vacancy}', [CareersController::class, 'store'])->whereNumber('vacancy')->name('recruitment.careers.apply');
});
