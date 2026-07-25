<?php

namespace App\Listeners;

use App\Models\Company;
use Filament\Events\TenantSet;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bridges Filament's active tenant to spatie/laravel-multitenancy.
 *
 * When a dedicated tenant database connection is configured (production), the
 * company is made "current" so its database connection and cache prefix are
 * activated. In the single-database test environment (no dedicated tenant
 * connection) we only scope the permission team id, leaving the shared test
 * database untouched.
 */
class SyncSpatieTenant
{
    public function handle(TenantSet $event): void
    {
        $tenant = $event->getTenant();

        if (! $tenant instanceof Company) {
            return;
        }

        if (config('multitenancy.tenant_database_connection_name')) {
            $tenant->makeCurrent();

            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    }
}
