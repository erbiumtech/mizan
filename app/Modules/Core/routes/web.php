<?php

use App\Modules\Core\Http\Controllers\SignupController;
use App\Modules\Core\Http\Controllers\Site\SiteController;
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

/**
 * Caddy's `ask` — docs/saas-launch-plan.md §8.8 and deploy/caddy/README.md. 200 only for a host a
 * company has claimed, so a certificate is never issued for a domain that merely points here.
 */
Route::get('/domains/check', function (\Illuminate\Http\Request $request) {
    abort_unless(\App\Support\CustomDomains::companyForHost((string) $request->query('domain')), 404);

    return response('OK', 200, ['Content-Type' => 'text/plain']);
})->middleware('throttle:signup')->name('domains.check');

/*
 * The public site — docs/company-website-plan.md. Generated from config/plans.php and the module
 * manifests, with the few hand-written pages edited in the platform panel. Every route answers
 * 404 when `site.enabled` is off (the controller checks it), and `/` — in routes/web.php — falls
 * back to the old redirect into the admin panel.
 */
Route::middleware('web')->group(function (): void {
    Route::get('/pricing', [SiteController::class, 'pricing'])->name('site.pricing');
    Route::get('/features', [SiteController::class, 'features'])->name('site.features');
    Route::get('/features/{module}', [SiteController::class, 'feature'])->where('module', '[a-z_]+')->name('site.feature');
    Route::get('/p/{slug}', [SiteController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('site.page');
    Route::get('/contact', [SiteController::class, 'contact'])->name('site.contact');
    // Not behind `throttle:`, for the signup door's reason: the controller throttles silently.
    Route::post('/contact', [SiteController::class, 'send'])->name('site.contact.send');
});

Route::get('/sitemap.xml', [SiteController::class, 'sitemap'])->name('site.sitemap');
Route::get('/robots.txt', [SiteController::class, 'robots'])->name('site.robots');
