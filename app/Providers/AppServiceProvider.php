<?php

namespace App\Providers;

use App\Health\TenantDatabaseCheck;
use App\Listeners\SyncSpatieTenant;
use App\Support\EmployeeAccess;
use App\Support\ModuleAuthorization;
use App\Support\ModuleMap;
use App\Support\Modules;
use App\Support\NavigationBadge;
use App\Support\TenantSettings;
use App\Support\WhatsApp\CloudApiWhatsAppSender;
use App\Support\WhatsApp\LogWhatsAppSender;
use App\Support\WhatsApp\TwilioWhatsAppSender;
use App\Support\WhatsApp\WhatsAppSender;
use Filament\Events\TenantSet;
use Filament\Resources\Resource;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

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

        // The sidebar's badge counts, memoised for the length of one request.
        //
        // `scoped` rather than `singleton`, and the difference is the whole
        // point: these figures belong to one company and one user, and an
        // instance that answered a second request would answer it with the first
        // one's numbers. Same reasoning as NavigationSnapshot, which says more
        // about why the container is where this belongs.
        $this->app->scoped(NavigationBadge::class);

        // Whichever WhatsApp sender the environment is configured for. The log
        // sender is the default and the fallback: an install with the driver set
        // to "cloud" but no credentials sends nothing rather than throwing in the
        // middle of a payroll run, and says so in the log.
        $this->app->singleton(WhatsAppSender::class, function (): WhatsAppSender {
            $driver = config('whatsapp.driver');
            $cloud = config('whatsapp.cloud');
            $twilio = config('whatsapp.twilio');

            if ($driver === 'cloud' && filled($cloud['phone_number_id']) && filled($cloud['token'])) {
                return new CloudApiWhatsAppSender(
                    phoneNumberId: (string) $cloud['phone_number_id'],
                    token: (string) $cloud['token'],
                    apiVersion: (string) ($cloud['api_version'] ?? 'v21.0'),
                    template: $cloud['template'] ?: null,
                    templateLanguage: (string) ($cloud['template_language'] ?? 'en'),
                );
            }

            if ($driver === 'twilio' && filled($twilio['account_sid']) && filled($twilio['auth_token']) && filled($twilio['from'])) {
                return new TwilioWhatsAppSender(
                    accountSid: (string) $twilio['account_sid'],
                    authToken: (string) $twilio['auth_token'],
                    from: (string) $twilio['from'],
                    apiBase: (string) ($twilio['api_base'] ?? 'https://api.twilio.com/2010-04-01'),
                    contentSid: $twilio['content_sid'] ?: null,
                    templateMediaBase: $twilio['template_media_base'] ?: null,
                );
            }

            // Either no driver chosen or one chosen without its credentials. Both
            // land here rather than throwing mid-payroll, and the log says what
            // would have gone where.
            return new LogWhatsAppSender;
        });

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
        /*
         * Lazy loading is an error everywhere except production.
         *
         * A relation read without being loaded is a query per row, and the rows are the part nobody
         * sees while developing: a table of four in a test looks fine and the same page over four
         * hundred employees is four hundred queries. This turns that into a failure at the moment it
         * is written instead of a support ticket about a slow screen.
         *
         * **Not in production**, deliberately, and this is the whole reason for the condition rather
         * than a bare `true`: the guard throws. A relation nobody exercised in development would take
         * a customer's page down rather than serve it a little slower, which is a worse trade than the
         * one it is here to make. Phase 6 of docs/page-load-performance-plan.md.
         */
        Model::preventLazyLoading(! $this->app->isProduction());

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

        // Laravel derives a factory's name from the model's namespace — for
        // App\Modules\Core\Models\Company it looks for
        // Database\Factories\Modules\Core\Models\CompanyFactory. Factories stay in
        // one flat directory (the landlord/tenant split is orthogonal to modules,
        // and there are three of them), so resolve on the class basename instead.
        Factory::guessFactoryNamesUsing(
            fn (string $model) => 'Database\\Factories\\'.class_basename($model).'Factory'
        );

        // Every policy is registered by the module that owns the model — see any
        // module's ServiceProvider. Laravel's App\Models\X -> App\Policies\XPolicy
        // guess cannot resolve a class in a module directory, and Filament reads
        // "no policy" as "allowed", so ModuleCoverageTest asserts the coverage.

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
        //
        // This holds only for models in a tenant database. Anything in the
        // landlord database is shared by every company and has to draw the
        // boundary by hand: ActivityLog and TableView carry a company_id and
        // scope on it themselves, and UserResource turns row scoping back on
        // (see the $isScopedToTenant there) because membership is a pivot.
        Resource::scopeToTenant(false);

        // Keep spatie/laravel-multitenancy's current tenant in sync with the
        // tenant Filament resolves from the /admin/{company} route.
        Event::listen(TenantSet::class, SyncSpatieTenant::class);

        $this->registerHealthChecks();
    }

    /**
     * What `health:check` looks at.
     *
     * Registered here rather than in a module, because every one of these is a fact about the
     * *installation* — is the disk full, is Redis answering, is the scheduler running — and not
     * about any company. A module's checks would come and go with its licence, which is the
     * wrong lifetime for "is the server alright".
     *
     * The set is deliberately small. A dashboard of thirty checks where two are always amber is
     * a dashboard nobody reads, and the point of this is that somebody looks when it goes red.
     * Notably absent:
     *
     *  - **`QueueCheck`** needs `Schedule::job(new HealthQueueJob)` plus a worker consuming it.
     *    Registering it without both means a permanent failure that says "queue broken" when
     *    what is broken is the check's own plumbing. `HorizonCheck` below covers the same ground
     *    more directly now that Horizon supervises the workers.
     *  - **`OptimizedAppCheck`** fails by design in local development, where nothing is cached.
     */
    private function registerHealthChecks(): void
    {
        Health::checks([
            // The landlord. Its own check, named so, because in this application "the database"
            // is an ambiguous phrase and a green tick against it has meant less than people think.
            DatabaseCheck::new()
                ->connectionName(config('database.default'))
                ->name('Landlord database'),

            // Every company's database. The one the package cannot express — see the class.
            TenantDatabaseCheck::new()->name('Company databases'),

            // Redis carries the queue (QUEUE_CONNECTION=redis) and broadcasting, so when it is
            // down, scheduled work silently stops being done rather than failing loudly.
            RedisCheck::new(),

            CacheCheck::new(),

            // Backups, uploads and PDF temp files all land on the same volume, and the failure
            // mode of a full disk is a backup that half-writes.
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(70)
                ->failWhenUsedSpaceIsAbovePercentage(85),

            // Proof that `schedule:run` is on cron. This application leans on it heavily —
            // payroll posting, leave-year opening, document expiry, quote expiry, compensatory
            // off — and every one of those fails by simply never happening, which is invisible.
            // Needs `health:schedule-check-heartbeat` scheduled every minute; it is, in
            // routes/console.php, beside this comment's twin.
            ScheduleCheck::new(),

            // The archives spatie/laravel-backup writes. This watches the LANDLORD destination
            // only, for the reason config/backup.php's monitor_backups block spells out: tenant
            // archives live under sibling backup names on purpose, so they cannot keep this
            // green while the landlord backup fails. A stale *tenant* archive is still not
            // monitored — `backup:tenants` exiting non-zero is the signal.
            BackupsCheck::new()
                ->locatedAt(storage_path('app/private/'.config('backup.backup.name').'/*.zip'))
                ->numberOfBackups(min: 1)
                ->youngestBackShouldHaveBeenMadeBefore(now()->subDay()),

            // Horizon supervises the queue workers, so "is Horizon running" is the closest thing
            // to "is queued work being done at all". It reports paused and inactive separately,
            // which matters: a paused Horizon looks alive to `ps` and does nothing.
            //
            // This is what makes the scheduled work in every module observable end to end —
            // ScheduleCheck proves cron fires the dispatcher, this proves something is on the
            // other end to run what it dispatched.
            HorizonCheck::new(),
        ]);

        // Two that are only meaningful in production, and are registered only there.
        //
        // Both fail by definition in local development — `APP_DEBUG` is true and `APP_ENV` is
        // `local`, which is correct and not a problem. Registering them anyway would mean every
        // developer's `health:check` shows two permanent reds, and two permanent reds are how a
        // dashboard stops being read. Absent locally rather than green: a check that lies in the
        // reassuring direction is worse than one that is not there.
        if ($this->app->environment('production')) {
            Health::checks([
                DebugModeCheck::new(),
                EnvironmentCheck::new(),
            ]);
        }
    }
}
