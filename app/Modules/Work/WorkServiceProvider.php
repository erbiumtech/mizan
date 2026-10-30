<?php

namespace App\Modules\Work;

use Illuminate\Support\ServiceProvider;

/**
 * Policies, registries and scheduled work for the work module.
 *
 * Empty in Phase 0 and registered anyway: the provider is what every later phase hangs things on —
 * the model policies in Phase 1, the webhook event types in Phase 6, the integration providers in
 * Phase 7 — and adding it to `bootstrap/providers.php` once is better than a phase that has to
 * remember to.
 */
class WorkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}
