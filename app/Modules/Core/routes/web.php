<?php

use App\Modules\Core\Http\Controllers\SignupController;
use App\Modules\Core\Http\Controllers\VerificationController;
use App\Modules\Core\Http\Middleware\ResolveVerificationTenant;
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

    // Document verification — plan §7.5. Public, behind the two gates; 404 for both.
    Route::get('/verify/{company}/{token}/{kind}/{id}/{hash}', [VerificationController::class, 'show'])
        ->middleware([ResolveVerificationTenant::class, 'throttle:signup'])
        ->whereNumber('id')
        ->where('kind', '[a-z-]+')
        ->where('hash', '[a-f0-9]{32}')
        ->name('documents.verify');
});
