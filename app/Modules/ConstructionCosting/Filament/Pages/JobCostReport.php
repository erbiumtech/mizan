<?php

namespace App\Modules\ConstructionCosting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\EarnedValue;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * What this job has cost, per cost code — `docs/construction-management-plan.md` §3.5's actual column.
 *
 * **Computed live, and §3.2's invariant is what makes that safe.** The sum of `amount` filtered by nothing but
 * the period *is* the cost, so there is no snapshot to go stale and no flag to forget. A reversal is a negative
 * row cancelling its original inside the same sum, which is why this is one `group by` rather than a pipeline of
 * adjustments.
 *
 * Phase 3 added the other three of §3.5's four columns and §14's earned value. **Committed is still `—` rather
 * than `0.00`** and stays that way until Phase 5's procurement exists: a zero there reads as "nothing is on order",
 * which is the wrong thing to tell somebody deciding whether a code has room left in it.
 *
 * §14's rule is enforced at the source and merely displayed here: where the budget is not time-phased there is no
 * planned value, so schedule variance and SPI are **null and print as "unavailable" with the reason** — never as
 * 0.00, which reads as exactly on programme and is the most reassuring wrong answer this module could give.
 *
 * Rolls up the job tree, so a development shows its towers and a tower shows itself (§1.2).
 */
class JobCostReport extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.construction.job-cost-report';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $title = 'Job cost report';

    protected static ?int $navigationSort = 40;

    public ?array $data = [];

    /**
     * Its own permission rather than the resource's, because a page with its own `canAccess()` silently shadows
     * the module trait's — the trap `docs/new-module-checklist.md` §11 names, where the answer is to call
     * `moduleIsAvailable()` explicitly.
     */
    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('ConstructionCostView') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill([
            'job_id' => Job::query()->live()->orderBy('code')->value('id'),
            'period_start' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Select::make('job_id')
                    ->label('Job')
                    ->options(fn (): array => Job::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->live()
                    ->selectablePlaceholder(false),

                Select::make('period_start')
                    ->label('Period')
                    ->options(fn (): array => CostPeriod::query()->orderByDesc('period_start')->get()
                        ->mapWithKeys(fn (CostPeriod $p): array => [$p->period_start->toDateString() => $p->label()])
                        ->all())
                    ->placeholder('Whole job to date')
                    ->live()
                    ->helperText('One month, or everything so far.'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-job-cost', 'Job cost: Help')];
    }

    public function selectedJob(): ?Job
    {
        return ($id = $this->data['job_id'] ?? null) ? Job::query()->find($id) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        $job = $this->selectedJob();

        return $job ? app(CostLedger::class)->reportFor($job, $this->data['period_start'] ?? null) : [];
    }

    public function total(): float
    {
        $job = $this->selectedJob();

        return $job ? app(CostLedger::class)->totalFor($job, $this->data['period_start'] ?? null) : 0.0;
    }

    /**
     * §3.5's four-column report: budget, committed, actual and forecast per cost code.
     *
     * The page this module is bought for. `committed` and `forecast_final` come back null rather than zero where
     * there is nothing to say, and the view prints an em dash for each — see the class docblock.
     *
     * @return array<int, array<string, mixed>>
     */
    public function controlRows(): array
    {
        $job = $this->selectedJob();

        return $job ? app(CostLedger::class)->fourColumnReport($job, $this->data['period_start'] ?? null) : [];
    }

    /**
     * §14's metrics at the selected period.
     *
     * With no period chosen the current month is used rather than nothing: earned value is a to-date figure and
     * needs a date to be to. The whole-job view of the table above is the sum of every period, which is the same
     * thing at the latest one.
     *
     * @return array<string, mixed>|null
     */
    public function metrics(): ?array
    {
        $job = $this->selectedJob();

        if ($job === null) {
            return null;
        }

        $period = $this->data['period_start'] ?? CostPeriod::startFor(now())->toDateString();

        return app(EarnedValue::class)->metricsFor($job, $period);
    }

    /**
     * The four columns totalled.
     *
     * Summed over the rows rather than re-queried, and **null propagates**: a total forecast is only a number if
     * every line has one. A partial total that looks complete is how a forecast comes to be read as the job's.
     *
     * @return array<string, float|null>
     */
    public function controlTotals(): array
    {
        $rows = $this->controlRows();

        $totals = ['budget' => 0.0, 'actual' => 0.0, 'accrued' => 0.0, 'forecast_final' => 0.0, 'variance' => 0.0];
        $forecastComplete = $rows !== [];

        foreach ($rows as $row) {
            $totals['budget'] += $row['budget'];
            $totals['actual'] += $row['actual'];
            $totals['accrued'] += $row['accrued'];

            if ($row['forecast_final'] === null) {
                $forecastComplete = false;

                continue;
            }

            $totals['forecast_final'] += $row['forecast_final'];
            $totals['variance'] += $row['variance'];
        }

        if (! $forecastComplete) {
            $totals['forecast_final'] = null;
            $totals['variance'] = null;
        }

        return $totals;
    }

    /**
     * The cost by type — the first cut anybody asks for.
     *
     * "How much of this job is labour" comes before any per-code question, and it is a fold over the rows this
     * page already has rather than a second query.
     *
     * @return array<string, float>
     */
    public function byType(): array
    {
        $totals = [];

        foreach ($this->rows() as $row) {
            $totals[$row['cost_type']] = ($totals[$row['cost_type']] ?? 0.0) + $row['amount'];
        }

        arsort($totals);

        return $totals;
    }
}
