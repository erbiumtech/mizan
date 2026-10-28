<?php

use App\Modules\Core\Http\Controllers\SignupController;
use Illuminate\Support\Facades\Route;

/*
 * The signup door — docs/saas-launch-plan.md §5. Public by design; the rules
 * that keep it from being a spam sink are in the controller.
 *
 * The POST is NOT behind `throttle:` middleware, deliberately: a 429 tells a
 * bot how to pace itself, so the controller throttles silently and answers a
 * throttled submission with the same page as a successful one. The signed
 * verification link is throttled the ordinary way — it is a link, not a form.
 */
Route::middleware('web')->group(function (): void {
    Route::get('/signup', [SignupController::class, 'create'])->name('signup');
    Route::post('/signup', [SignupController::class, 'store'])->name('signup.store');

    Route::get('/signup/verify/{user}/{hash}', [SignupController::class, 'verify'])
        ->middleware(['signed', 'throttle:signup'])
        ->whereNumber('user')
        ->name('signup.verify');

    Route::get('/signup/ready', [SignupController::class, 'ready'])
        ->middleware('auth')
        ->name('signup.ready');
});
