<?php

namespace App\Listeners;

use App\Modules\Core\Models\Company;
use Filament\Events\TenantSet;

/**
 * Bridges Filament's active tenant to spatie/laravel-multitenancy.
 *
 * What "activating" a company means — the database connection, the cache prefix,
 * the permission team id, and what of that applies in the single-database test
 * environment — is Company::activate(), shared with the pages that resolve a
 * company from the URL rather than from the panel.
 */
class SyncSpatieTenant
{
    public function handle(TenantSet $event): void
    {
        $tenant = $event->getTenant();

        if (! $tenant instanceof Company) {
            return;
        }

        $tenant->activate();
    }
}
