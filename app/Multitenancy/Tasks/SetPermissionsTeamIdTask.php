<?php

namespace App\Multitenancy\Tasks;

use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;
use Spatie\Permission\PermissionRegistrar;

/**
 * Scopes spatie/laravel-permission "teams" to the current company, so a user's
 * roles/permissions resolve per company. Runs whenever a tenant is made current
 * or forgotten as part of Spatie's switch-tenant task pipeline.
 */
class SetPermissionsTeamIdTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    }

    public function forgetCurrent(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
