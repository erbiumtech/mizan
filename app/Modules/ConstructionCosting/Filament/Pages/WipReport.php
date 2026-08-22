<?php

namespace App\Modules\ConstructionCosting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\WipSnapshot;
use App\Modules\ConstructionCosting\Services\WipService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Work in progress — `docs/construction-management-plan.md` §4.4.
 *
 * The page a bank, an auditor and a board all read, and it carries the two positions every construction balance sheet
 * has: **costs and recognised profit in excess of billings**, and **billings in excess of costs**.
 *
 * **Unlocked months recompute; locked ones do not.** §4.4 is the one place this plan permits a stored total, and it
 * argues the exception: "a WIP position is a judgement at a point in time — the surveyor's forecast, the surveyed
 * percentage, the loss provision — not a derivation from immutable facts. Recomputing last March's WIP with today's
 * forecast would silently restate a month that was signed off, reported to a bank and used to compute a bonus."
 *
 * So the page says which it is showing, on every row. A figure a bank has seen and a figure that will change by Friday
 * look identical on paper, and the difference matters more than either number.
 *
 * **Pending variations sit next to the contract value and are not in it** — §4.4 — because a job whose contract value
 * looks comfortable while eleven million of variations sit unapproved is a job about to be in trouble.
 *
 * **And a loss is whole.** When the forecast final cost exceeds the contract value the entire expected loss is on this
 * page now, at 4% complete or 96%. A loss spread across the remaining months is the commonest way a loss-making contract
 * reports as profitable until the month it finishes.
 */
class WipReport extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.construction.wip-report';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $title = 'Work in progress';

    protected static ?int $navigationSort = 61;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('ConstructionCostView') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill([
            'period_start' => (CostPeriod::earliestOpen() ?? CostPeriod::forDate(now()))
                ->period_start->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Select::make('period_start')
                    ->label('Cost month')
                    ->options(fn (): array => CostPeriod::query()->orderByDesc('period_start')->get()
                        ->mapWithKeys(fn (CostPeriod $p): array => [
                            $p->period_start->toDateString() => $p->label().' — '.$p->status,
                        ])
                        ->all())
                    ->live()
                    ->selectablePlaceholder(false),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-wip', 'Work in progress: Help'),

            /*
             * Recomputing every live job's position. A read that keeps its answer, so it rides on the view grant.
             *
             * The refusals are collected and shown rather than thrown: one job with no percent-complete method must not
             * stop the other forty from having a position, and a company setting the module up has all forty unset.
             */
            Action::make('recompute')
                ->label('Recompute every job')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Recompute the open month')
                ->modalDescription('Recomputes every live job\'s position from today\'s forecast, measurements and '
                    .'certificates. Locked months are left exactly as they are — that is what locking them was for.')
                ->action(function (): void {
                    $refused = [];
                    $computed = 0;

                    foreach (Job::query()->live()->orderBy('code')->get() as $job) {
                        try {
                            app(WipService::class)->compute($job, $this->periodStart());
                            $computed++;
                        } catch (\InvalidArgumentException $e) {
                            $refused[] = $e->getMessage();
                        }
                    }

                    Notification::make()
                        ->status($refused === [] ? 'success' : 'warning')
                        ->title($computed.' position(s) computed')
                        ->body($refused === []
                            ? 'Every live job has a position for this month.'
                            : count($refused).' job(s) could not be computed. '.$refused[0])
                        ->persistent($refused !== [])
                        ->send();
                }),
        ];
    }

    public function periodStart(): string
    {
        return $this->data['period_start'] ?? CostPeriod::startFor(now())->toDateString();
    }

    /**
     * Every stored position for the month.
     *
     * Stored rather than computed on render, deliberately: a page that recomputed forty jobs on every keystroke would
     * be a page nobody opens, and §4.4's locked months must not be recomputed at all. Recomputing is the button above.
     *
     * @return Collection<int, WipSnapshot>
     */
    public function rows(): Collection
    {
        return WipSnapshot::query()
            ->with('job', 'previousSnapshot')
            ->forPeriod($this->periodStart())
            ->join('construction_jobs', 'construction_jobs.id', '=', 'construction_wip_snapshots.job_id')
            ->orderBy('construction_jobs.code')
            ->select('construction_wip_snapshots.*')
            ->get();
    }

    /**
     * The month's totals.
     *
     * **Contract asset and liability are totalled separately and never netted.** They are opposite sides of the balance
     * sheet; a single net figure would let a job with a large asset hide another with a large liability, and the two are
     * different conversations with the bank.
     *
     * @return array<string, float>
     */
    public function totals(): array
    {
        $rows = $this->rows();

        return [
            'cost_to_date' => round((float) $rows->sum(fn (WipSnapshot $r): float => $r->totalCost()), 2),
            'contract_value' => round((float) $rows->sum('contract_value'), 2),
            'variations_pending' => round((float) $rows->sum('variations_pending'), 2),
            'revenue_recognised' => round((float) $rows->sum('revenue_recognised'), 2),
            'billings_to_date' => round((float) $rows->sum('billings_to_date'), 2),
            'provision_for_loss' => round((float) $rows->sum('provision_for_loss'), 2),
            'contract_asset' => round((float) $rows->sum('contract_asset'), 2),
            'contract_liability' => round((float) $rows->sum('contract_liability'), 2),
        ];
    }

    /** How many of the month's positions are frozen, so the page can say what a reader is looking at. */
    public function lockedCount(): int
    {
        return $this->rows()->filter(fn (WipSnapshot $r): bool => $r->isLocked())->count();
    }

    /**
     * Live jobs with no position this month, and why.
     *
     * Named rather than left out: a WIP report missing a job is a balance sheet missing a contract, and the commonest
     * reason is a percent-complete method nobody has chosen — which is a two-second fix somebody has to be told about.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        $covered = $this->rows()->pluck('job_id')->all();
        $missing = [];

        foreach (Job::query()->live()->orderBy('code')->get() as $job) {
            if (in_array($job->getKey(), $covered, true)) {
                continue;
            }

            $missing[] = $job->percent_complete_method === null
                ? "{$job->code} — {$job->name}: no percent-complete method chosen on the job."
                : "{$job->code} — {$job->name}: never computed for this month.";
        }

        return $missing;
    }
}
