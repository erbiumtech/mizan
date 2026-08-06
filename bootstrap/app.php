<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Licensing is enforced per route, not globally: most routes belong to a
        // module, and the ones that do not (login, the panel shell, tenant file
        // downloads) must stay reachable whatever a company has bought.
        $middleware->alias([
            'module' => App\Http\Middleware\EnsureModuleEnabled::class,
            // For pages outside the panel, which have no tenant otherwise. List it
            // before `module:` — a licence belongs to a company, so one has to be
            // current before that question can be answered.
            'company' => App\Http\Middleware\ResolveCompanyFromRoute::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
