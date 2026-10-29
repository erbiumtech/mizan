<?php

namespace App\Modules\Performance;

use App\Modules\Employees\Events\LateMarkRecorded;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Console\Commands\AwardBadges;
use App\Modules\Performance\Console\Commands\RollTasks;
use App\Modules\Performance\Console\Commands\SettleTaskPenalties;
use App\Modules\Performance\Filament\Pages\MeritLedgerReport;
use App\Modules\Performance\Filament\Pages\ReviewCycleProgress;
use App\Modules\Performance\Listeners\DebitLateMark;
use App\Modules\Performance\Models\Badge;
use App\Modules\Performance\Models\DailyReport;
use App\Modules\Performance\Models\EmployeeBadge;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\OneToOne;
use App\Modules\Performance\Models\RecurringTask;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Modules\Performance\Policies\BadgePolicy;
use App\Modules\Performance\Policies\DailyReportPolicy;
use App\Modules\Performance\Policies\EmployeeBadgePolicy;
use App\Modules\Performance\Policies\GoalPolicy;
use App\Modules\Performance\Policies\MeritEntryPolicy;
use App\Modules\Performance\Policies\OneToOnePolicy;
use App\Modules\Performance\Policies\RecurringTaskPolicy;
use App\Modules\Performance\Policies\ReviewCyclePolicy;
use App\Modules\Performance\Policies\ReviewPolicy;
use App\Modules\Performance\Policies\TaskOccurrencePolicy;
use App\Modules\Performance\Services\Badges;
use App\Modules\Performance\Support\PerformanceReports;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\ResourceContributions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class PerformanceServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        ReviewCycle::class => ReviewCyclePolicy::class,
        Review::class => ReviewPolicy::class,
        \App\Modules\Performance\Models\ReviewTemplate::class => \App\Modules\Performance\Policies\ReviewTemplatePolicy::class,
        \App\Modules\Performance\Models\ReviewCompetency::class => \App\Modules\Performance\Policies\ReviewTemplatePolicy::class,
        \App\Modules\Performance\Models\ReviewRating::class => \App\Modules\Performance\Policies\ReviewRatingPolicy::class,
        Goal::class => GoalPolicy::class,
        OneToOne::class => OneToOnePolicy::class,
        // The ledger and recurring tasks — docs/saas-launch-plan.md §8.1, §8.2.
        MeritEntry::class => MeritEntryPolicy::class,
        RecurringTask::class => RecurringTaskPolicy::class,
        TaskOccurrence::class => TaskOccurrencePolicy::class,
        // Daily reports and badges — §8.3, §8.4.
        DailyReport::class => DailyReportPolicy::class,
        Badge::class => BadgePolicy::class,
        EmployeeBadge::class => EmployeeBadgePolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // reports.*, tasks.*, merit.balance for the rule builder — docs/saas-launch-plan.md §8.9.
        foreach (\App\Modules\Performance\Rules\PerformanceMetrics::all() as $metric) {
            \App\Modules\Employees\Rules\RuleRegistry::registerMetric($metric);
        }

        $this->registerReports();

        // A late mark as evidence — plan §8.1, zero points as shipped. Attendance
        // announces; this module decides what it means.
        Event::listen(LateMarkRecorded::class, DebitLateMark::class);

        // "Award badge" on the employee view — plan §8.4. Contributed, because this
        // module requires Employees and the page may not name this one back.
        ResourceContributions::addHeaderAction(ViewEmployee::class, 'awardBadge', fn () => $this->awardBadgeAction());

        // Recurring tasks — plan §8.2: the phone's list, and the two commands.
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        $this->commands([RollTasks::class, SettleTaskPenalties::class, AwardBadges::class]);
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
    private function awardBadgeAction(): Action
    {
        $badges = fn (): Badges => app(Badges::class);

        return Action::make('awardBadge')
            ->label('Award badge')
            ->icon('heroicon-o-trophy')
            ->color('gray')
            ->visible(fn (Employee $record): bool => modules()->enabled('performance')
                && (auth()->user()?->can('create', EmployeeBadge::class) ?? false)
                && $badges()->lockedFor($record)->isNotEmpty())
            ->schema([
                Select::make('badge_id')
                    ->label('Badge')
                    ->options(fn (Employee $record): array => $badges()->lockedFor($record)->pluck('name', 'id')->all())
                    ->required(),
                Textarea::make('reason')->rows(2)->required()->maxLength(200)->helperText('Printed on the award and on the ledger.'),
            ])
            ->action(function (Employee $record, array $data) use ($badges): void {
                try {
                    $badges()->award(Badge::query()->findOrFail($data['badge_id']), $record, (string) $data['reason'], auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Badge awarded.')->send();
            });
    }

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
