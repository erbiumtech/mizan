<?php

namespace App\Modules\Employees;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — plugin
 * registration and resource-route generation happen at boot, while the company is
 * resolved per request. See MprPlugin for the full reasoning.
 */
class EmployeesPlugin implements Plugin
{
    public function getId(): string
    {
        return 'employees';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
