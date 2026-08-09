<?php

namespace Tests\Concerns;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Filament\Facades\Filament;

/**
 * Helper for tests that render the (now tenant-scoped) admin panel. Filament
 * requires a current tenant to build /admin/{company} URLs; this creates a
 * company and sets it as the panel's current tenant.
 */
trait InteractsWithTenant
{
    protected ?Company $tenant = null;

    /**
     * Pass a company to manage its membership yourself — that is what a test
     * about isolation between companies needs. Ask for one and it adopts the
     * users the test has already created, described at the bottom of this method.
     */
    protected function setCurrentTenant(?Company $company = null): Company
    {
        $adoptExistingUsers = $company === null;

        $this->tenant = $company ?? Company::factory()->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Boot the panel, as the SetUpPanel middleware does for a real request.
        // Panel::boot() is what registers the tenancy global scopes for resources
        // that keep row-level scoping (UserResource), so without this a test sets
        // a tenant but sees none of the scoping the browser gets — which is
        // exactly how the Users list came to leak every company's accounts with a
        // passing suite.
        Filament::bootCurrentPanel();

        Filament::setTenant($this->tenant);

        // Fixtures are built before the company they belong to: a test creates its
        // users and employees, then asks for a tenant. Those users predate the
        // company and would be members of nothing, a state the app has no way to
        // produce — you reach a company's panel by being a member (canAccessTenant
        // checks the same pivot UserResource scopes on), users created in it are
        // attached to it, and MigrateExistingToTenant backfills the whole landlord
        // table into the first company. So adopt them, exactly as that migration
        // does, rather than have every test remember to.
        if ($adoptExistingUsers) {
            $this->tenant->users()->syncWithoutDetaching(
                User::query()->acrossCompanies()->pluck('id')->all()
            );
        }

        // Not for a super admin. canAccessTenant() lets them into any company
        // without a membership row, and reaching a company they are not a member
        // of is precisely what their tests are about — manufacturing one here
        // would quietly remove the case under test.
        if (($user = auth()->user()) instanceof User && ! $user->isSuperAdmin()) {
            $user->companies()->syncWithoutDetaching([$this->tenant->getKey()]);
        }

        return $this->tenant;
    }
}
