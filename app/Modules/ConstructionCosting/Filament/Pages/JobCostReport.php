<?php

namespace App\Modules\ConstructionCosting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Services\CostLedger;
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
 * Budget, committed and forecast — the other three of the four columns — arrive in Phase 3 and Phase 5. This page
 * shows actual and the unit rate, which is the part that already answers the question the whole plan opens with:
 * what has this job cost.
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
