<?php

namespace App\Modules\Performance;

use App\Modules\Performance\Filament\Pages\ReviewCycleProgress;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\OneToOne;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Policies\GoalPolicy;
use App\Modules\Performance\Policies\OneToOnePolicy;
use App\Modules\Performance\Policies\ReviewCyclePolicy;
use App\Modules\Performance\Policies\ReviewPolicy;
use App\Modules\Performance\Support\PerformanceReports;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
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
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerReports();
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
    }
}
