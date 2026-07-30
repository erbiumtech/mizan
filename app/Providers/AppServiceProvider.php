<?php

namespace App\Providers;

use App\Listeners\SyncSpatieTenant;
use App\Policies\ActivityLogPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Support\EmployeeAccess;
use App\Support\ModuleAuthorization;
use App\Support\ModuleMap;
use App\Support\Modules;
use App\Support\TenantSettings;
use Filament\Events\TenantSet;
use Filament\Resources\Resource;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantSettings::class);
        $this->app->singleton(EmployeeAccess::class);
        $this->app->singleton(Modules::class);

        // Registered here, in register() rather than boot(), and this is load
        // bearing. Gate::before callbacks run in registration order, and
        // spatie/laravel-permission registers its own from its provider's boot()
        // (PermissionRegistrar::registerPermissions) which returns true the moment
        // the user holds the permission. A module check registered in boot() lands
        // behind it and never runs for exactly the users who do have the
        // permission — which is everyone the check is meant to stop. register()
        // runs before every provider's boot(), so this callback is first.
        //
        // It returns a hard false: a module the company has not licensed is not a
        // permission question, so neither a super admin nor an Administrator
        // bypasses it (see the two bypasses in boot()).
        Gate::before(function ($user, $ability, $arguments = []) {
            if (ModuleAuthorization::blockingModule($user, (string) $ability, (array) $arguments) !== null) {
                return false;
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Landlord (central) migrations always run on the default connection.
        // Tenant migrations live in their own path and are applied per-company
        // during provisioning; in the testing environment we also load them onto
        // the default connection so the suite runs against a single database.
        $this->loadMigrationsFrom(database_path('migrations/landlord'));

        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(database_path('migrations/tenant'));
        }

        // Routes outside the panel (file downloads, report pages) guard with the
        // plain `auth` middleware, which redirects guests to a route named
        // `login`. Only Filament defines a login screen here, so without this a
        // signed-out visitor gets a 500 instead of the sign-in page.
        Authenticate::redirectUsing(fn () => route('filament.admin.auth.login'));

        // Class names are stored as strings in customer data — comments.commentable_type,
        // payments.payable_type, activity_log.subject_type, custom_fields.model_type,
        // model_has_roles.model_type — so moving a model class would orphan every
        // one of those rows. The aliases are deliberately the legacy
        // `App\Models\…` strings that are already in the data: the alias stays
        // fixed while the target class moves into its module, so old and new rows
        // agree and no per-tenant data migration is needed.
        //
        // enforceMorphMap (rather than plain morphMap) makes a model missing from
        // ModuleMap throw on first use instead of silently writing an unmapped
        // FQCN back into the data. That noise is the point.
        Relation::enforceMorphMap(ModuleMap::morphMap());

        // MPR's policy is registered by MprServiceProvider, which owns that model
        // now. The reason it was ever explicit still applies to every moved
        // module: Laravel's guess cannot find a policy outside App\Policies.
        Gate::policy(Activity::class, ActivityLogPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);

        // The module deny that runs ahead of both of these is registered in
        // register() — see the comment there for why the ordering matters.
        Gate::before(function ($user, $ability) {
            // Global super admin bypasses all authorization.
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }

            if ($user->hasRole('Administrator') && $ability !== 'create') {
                return true;
            }
        });
        Schema::defaultStringLength(191);

        // Store Livewire temp uploads on a fixed, non-tenant-scoped disk so the
        // file written during upload is still found when validation runs under a
        // (possibly different) current tenant. See the `livewire-tmp` disk.
        config(['livewire.temporary_file_upload.disk' => 'livewire-tmp']);

        // Isolation is enforced at the database level (one database per company),
        // so Filament's row-level tenant scoping is disabled — resource queries
        // already run against the current tenant's database connection.
        Resource::scopeToTenant(false);

        // Keep spatie/laravel-multitenancy's current tenant in sync with the
        // tenant Filament resolves from the /admin/{company} route.
        Event::listen(TenantSet::class, SyncSpatieTenant::class);
    }
}
