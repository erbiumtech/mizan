<?php

namespace App\Providers;

use App\Listeners\SyncSpatieTenant;
use App\Policies\ActivityLogPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use Filament\Events\TenantSet;
use Filament\Resources\Resource;
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
        $this->app->singleton(\App\Support\TenantSettings::class);
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

        Gate::policy(Activity::class, ActivityLogPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);

        Gate::before(function ($user, $ability) {
            if ($user->hasRole('Administrator') && $ability !== 'create') {
                return true;
            }
        });
        Schema::defaultStringLength(191);

        // Isolation is enforced at the database level (one database per company),
        // so Filament's row-level tenant scoping is disabled — resource queries
        // already run against the current tenant's database connection.
        Resource::scopeToTenant(false);

        // Keep spatie/laravel-multitenancy's current tenant in sync with the
        // tenant Filament resolves from the /admin/{company} route.
        Event::listen(TenantSet::class, SyncSpatieTenant::class);
    }
}
