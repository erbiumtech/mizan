<?php

namespace App\Modules\ConstructionField;

use App\Modules\ConstructionField\Console\Commands\CheckDelayNotices;
use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Models\Rfi;
use App\Modules\ConstructionField\Policies\DailyLogPolicy;
use App\Modules\ConstructionField\Policies\DelayEventPolicy;
use App\Modules\ConstructionField\Policies\RfiPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything this module owns that Filament does not discover.
 *
 * Policies explicitly: Laravel's App\Models\X -> App\Policies\XPolicy guess cannot resolve a model in a module
 * directory, and Filament treats a model with no policy as allowed — so a missing registration is an open resource.
 * `ModuleCoverageTest` fails the build for one.
 */
class ConstructionFieldServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        DelayEvent::class => DelayEventPolicy::class,
        DailyLog::class => DailyLogPolicy::class,
        Rfi::class => RfiPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Registered as well as scheduled: `Schedule::command()` in routes/console.php only wires the timetable, and a
        // command nobody can invoke by hand is a command nobody can test or re-run after a failed night.
        $this->commands([CheckDelayNotices::class]);

        $this->loadRoutesFrom(__DIR__.'/routes/console.php');
    }
}
