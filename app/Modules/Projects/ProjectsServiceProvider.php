<?php

namespace App\Modules\Projects;

use App\Modules\Projects\Console\Commands\CheckEnvironmentCertificates;
use App\Modules\Projects\Console\Commands\CheckEnvironmentsHealth;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Projects module owns that Filament does not discover.
 *
 * Policies are registered EXPLICITLY. Laravel guesses App\Models\X ->
 * App\Policies\XPolicy, which cannot resolve a model living in a module
 * directory, and Filament treats a model with no policy as allowed — so without
 * this map every resource here would be open to any authenticated user.
 * ModuleCoverageTest fails the build if one is missing.
 */
class ProjectsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Project::class => ProjectPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/routes/console.php');

        // Laravel only auto-discovers commands in app/Console/Commands,
        // so a moved command has to be registered here or it disappears
        // from artisan — and from the scheduler, silently.
        $this->commands([CheckEnvironmentCertificates::class, CheckEnvironmentsHealth::class]);
    }
}
