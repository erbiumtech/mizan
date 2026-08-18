<?php

namespace App\Providers;

use App\Support\ModuleManifest;
use Illuminate\Support\ServiceProvider;

/**
 * Puts the discovered module registry where the application already looks for it.
 *
 * `config('modules')` used to come from `config/modules.php`, a literal array of
 * 22 entries that every new module had to edit. It now comes from merging what
 * each module declares in its own `module.php`. Writing the result back into the
 * config repository rather than changing the readers is deliberate: there are
 * exactly three of them — `Modules::registry()`, `ModuleMap::moduleFor()` and the
 * `company_modules` backfill migration — and none of them needs to know.
 *
 * Registered **first** in bootstrap/providers.php, because every module provider
 * that follows may reach `config('modules')` while booting, and a module registry
 * that is empty for part of a request is a module registry that reports every
 * class as ungated.
 *
 * Note that this key is no longer covered by `config:cache`: the config directory
 * no longer holds it. ModuleManifest caches to bootstrap/cache/mizan-modules.php
 * instead, and `optimize:clear` clears it.
 */
class ModuleManifestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set('modules', ModuleManifest::all()['registry']);
    }
}
