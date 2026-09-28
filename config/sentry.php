<?php

/*
 * Overrides only — the package merges its own config/sentry.php underneath this
 * one, so the DSN (SENTRY_LARAVEL_DSN), environment, sample rates and breadcrumb
 * switches all keep their defaults. No DSN set means Sentry is off, which is the
 * correct state everywhere except production.
 *
 * Everything here is the PII posture, and it is deliberately not env-driven: a
 * payroll system's exposure to a third party is a code decision, reviewed in a
 * diff, not a value an .env edit can widen.
 */
return [

    // Static callables, NOT closures — deploy.sh runs `config:cache`, and a closure
    // in config makes every artisan command fail. See App\Support\SentryScrubber.
    'before_send' => [App\Support\SentryScrubber::class, 'scrub'],
    'before_breadcrumb' => [App\Support\SentryScrubber::class, 'scrubBreadcrumb'],

    // The cheapest scrub: request bodies are never sent at all. The URL, route and
    // method survive (scrubbed), which is enough to reproduce.
    'max_request_body_size' => 'none',

    // Hard false rather than the package's env(SENTRY_SEND_DEFAULT_PII): cookies,
    // session, auth headers and the user's identity stay out, and no environment
    // variable can turn them back on.
    'send_default_pii' => false,

];
