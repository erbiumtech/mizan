<?php

namespace App\Modules\Performance;

use App\Modules\Employees\Events\LateMarkRecorded;
use App\Modules\Performance\Console\Commands\RollTasks;
use App\Modules\Performance\Console\Commands\SettleTaskPenalties;
use App\Modules\Performance\Filament\Pages\MeritLedgerReport;
use App\Modules\Performance\Filament\Pages\ReviewCycleProgress;
use App\Modules\Performance\Listeners\DebitLateMark;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\OneToOne;
use App\Modules\Performance\Models\RecurringTask;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Modules\Performance\Policies\GoalPolicy;
use App\Modules\Performance\Policies\MeritEntryPolicy;
use App\Modules\Performance\Policies\OneToOnePolicy;
use App\Modules\Performance\Policies\RecurringTaskPolicy;
use App\Modules\Performance\Policies\ReviewCyclePolicy;
use App\Modules\Performance\Policies\ReviewPolicy;
use App\Modules\Performance\Policies\TaskOccurrencePolicy;
use App\Modules\Performance\Support\PerformanceReports;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PerformanceServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        ReviewCycle::class => ReviewCyclePolicy::class,
        Review::class => ReviewPolicy::class,
        Goal::class => GoalPolicy::class,
        OneToOne::class => OneToOnePolicy::class,
        // The ledger and recurring tasks — docs/saas-launch-plan.md §8.1, §8.2.
        MeritEntry::class => MeritEntryPolicy::class,
        RecurringTask::class => RecurringTaskPolicy::class,
        TaskOccurrence::class => TaskOccurrencePolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerReports();

        // A late mark as evidence — plan §8.1, zero points as shipped. Attendance
        // announces; this module decides what it means.
        Event::listen(LateMarkRecorded::class, DebitLateMark::class);

        // Recurring tasks — plan §8.2: the phone's list, and the two commands.
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        $this->commands([RollTasks::class, SettleTaskPenalties::class]);
    }

    /**
     * The review-cycle progress report — `docs/reports-expansion-plan.md` Phase 3.12.
     *
     * Filed under *People & payroll* with the rest of the employee reports: whoever chases a cycle to
     * completion is the same person chasing the paperwork on the other reports in that section.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without the performance module sees no entry rather than a
     * report that fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'People & payroll',
            ReviewCycleProgress::class,
            'Whether each cycle finished — reviews acknowledged, goals decided, conversations held.',
        );

        ReportRenderers::register(
            'ReviewCycleProgress',
            fn (string $asOf): array => app(PerformanceReports::class)->reviewCycleProgress($asOf),
        );

        ReportCatalogue::register(
            'People & payroll',
            MeritLedgerReport::class,
            'Every employee\'s merit credits, debits and balance for the period — the evidence behind a rating.',
        );
        ReportRenderers::register(
            'MeritLedgerReport',
            fn (string $asOf): array => app(PerformanceReports::class)->meritLedger($asOf),
        );
    }
}
