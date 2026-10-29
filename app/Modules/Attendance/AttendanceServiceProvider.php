<?php

namespace App\Modules\Attendance;

use App\Modules\Attendance\Console\Commands\AccrueCompensatoryOff;
use App\Modules\Attendance\Filament\Pages\AttendanceRegister;
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
use App\Modules\Attendance\Rules\AttendanceMetrics;
use App\Modules\Attendance\Services\WorkPatternResolver;
use App\Modules\Attendance\Support\AttendanceReports;
use App\Modules\Employees\Rules\RuleRegistry;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
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
        \App\Modules\Attendance\Models\OvertimeRequest::class => \App\Modules\Attendance\Policies\OvertimeRequestPolicy::class,
        \App\Modules\Attendance\Models\RestDaySwap::class => \App\Modules\Attendance\Policies\RestDaySwapPolicy::class,
    ];

    public function register(): void
    {
        // A singleton because it is memoised per request: the month calendar asks it
        // once per employee per day, and the leave-day generator once per day of a
        // request. A fresh instance per resolution would make the cache pointless.
        $this->app->singleton(WorkPatternResolver::class);

        // Attendance is the module that knows whether a given person works a given
        // day, so it answers the question rather than being reached into. Leave
        // asks the contract and no longer imports anything from here.
        $this->app->bind(
            \App\Support\Contracts\WorkingDayCalendar::class,
            \App\Modules\Attendance\Support\WorkPatternCalendar::class,
        );
    }

    public function boot(): void
    {
        $this->registerReports();

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->commands([AccrueCompensatoryOff::class, \App\Modules\Attendance\Console\Commands\RemindMissingPunches::class]);

        // attendance.* for the rule builder — docs/saas-launch-plan.md §8.9. Registered by the
        // module that owns the table, so a rule can only name what is there to read.
        foreach (AttendanceMetrics::all() as $metric) {
            RuleRegistry::registerMetric($metric);
        }

        // The phone's clock-in — plan §7.1. Loaded the way Leave loads its own.
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
    }

    /**
     * The monthly attendance register — `docs/reports-expansion-plan.md` Phase 3.1.
     *
     * Filed under *People & payroll*, beside the payroll register it is meant to be read against: the two
     * answer "what was worked" and "what was paid for" about the same month.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without attendance sees no entry rather than a report that
     * fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'People & payroll',
            AttendanceRegister::class,
            'A month of attendance per person, with paid days, loss of pay, late minutes and overtime.',
        );

        ReportCatalogue::register(
            'People & payroll',
            \App\Modules\Attendance\Filament\Pages\LateMarks::class,
            'Who is late, how often and by how much — ordered by the figure rather than by name.',
        );

        ReportCatalogue::register(
            'People & payroll',
            \App\Modules\Attendance\Filament\Pages\Absences::class,
            'Absent days per person for the month, beside the days nobody marked — which pay as present.',
        );

        ReportRenderers::register(
            'AttendanceRegister',
            fn (string $asOf): array => app(AttendanceReports::class)->register($asOf),
        );
        ReportRenderers::register(
            'LateMarks',
            fn (string $asOf): array => app(AttendanceReports::class)->lateMarks($asOf),
        );
        ReportRenderers::register(
            'Absences',
            fn (string $asOf): array => app(AttendanceReports::class)->absences($asOf),
        );
    }
}
