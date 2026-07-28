<?php

namespace App\Providers;

use App\Listeners\SyncSpatieTenant;
use App\Models\MPR;
use App\Policies\ActivityLogPolicy;
use App\Policies\MprPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Support\EmployeeAccess;
use App\Support\TenantSettings;
use Filament\Events\TenantSet;
use Filament\Resources\Resource;
use Illuminate\Auth\Middleware\Authenticate;
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

        // Registered explicitly because Laravel's guess does not match the file
        // name: App\Models\MPR maps to App\Policies\MPRPolicy, but the class is
        // MprPolicy. A case-insensitive filesystem hides that locally; on Linux
        // the policy is simply not found and the resource is open to everyone.
        Gate::policy(MPR::class, MprPolicy::class);

        Gate::policy(Activity::class, ActivityLogPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);

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
