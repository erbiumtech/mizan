<?php

namespace Tests\Concerns;

use App\Modules\Core\Models\Company;
use Filament\Facades\Filament;

/**
 * Helper for tests that render the (now tenant-scoped) admin panel. Filament
 * requires a current tenant to build /admin/{company} URLs; this creates a
 * company and sets it as the panel's current tenant.
 */
trait InteractsWithTenant
{
    protected ?Company $tenant = null;

    protected function setCurrentTenant(?Company $company = null): Company
    {
        $this->tenant = $company ?? Company::factory()->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        return $this->tenant;
    }
}
