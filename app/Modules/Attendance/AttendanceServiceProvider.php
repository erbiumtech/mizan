<?php

namespace App\Modules\Attendance;

use App\Modules\Attendance\Console\Commands\AccrueCompensatoryOff;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendanceRegularization;
use App\Modules\Attendance\Models\EmployeeWorkPattern;
use App\Modules\Attendance\Models\WorkPattern;
use App\Modules\Attendance\Models\WorkPatternDay;
use App\Modules\Attendance\Policies\AttendanceDayPolicy;
use App\Modules\Attendance\Policies\AttendanceRegularizationPolicy;
use App\Modules\Attendance\Policies\EmployeeWorkPatternPolicy;
use App\Modules\Attendance\Policies\WorkPatternDayPolicy;
use App\Modules\Attendance\Policies\WorkPatternPolicy;
use App\Modules\Attendance\Services\WorkPatternResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the Attendance module owns that Filament does not discover.
 *
 * Policies EXPLICITLY, because Laravel's App\Models\X -> App\Policies\XPolicy guess
 * cannot resolve a model in a module directory and Filament treats a model with no
 * policy as allowed.
 */
class AttendanceServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        WorkPattern::class => WorkPatternPolicy::class,
        WorkPatternDay::class => WorkPatternDayPolicy::class,
        EmployeeWorkPattern::class => EmployeeWorkPatternPolicy::class,
        AttendanceDay::class => AttendanceDayPolicy::class,
        AttendanceRegularization::class => AttendanceRegularizationPolicy::class,
    ];

    public function register(): void
    {
        // A singleton because it is memoised per request: the month calendar asks it
        // once per employee per day, and the leave-day generator once per day of a
        // request. A fresh instance per resolution would make the cache pointless.
        $this->app->singleton(WorkPatternResolver::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/console.php');

        $this->commands([AccrueCompensatoryOff::class]);
    }
}
