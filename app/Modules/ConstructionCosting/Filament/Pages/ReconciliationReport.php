<?php

namespace App\Modules\ConstructionCosting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\Reconciliation;
use App\Modules\ConstructionCosting\Services\ReconciliationService;
use App\Modules\ConstructionCosting\Support\ReconciliationResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * §4.2's statement — `docs/construction-management-plan.md` §4.2 and §4.3.
 *
 * §4 opens with the sentence this page answers: **"A second ledger that nobody proves is a second ledger that is
 * wrong."** §3 bought a job-cost ledger with unit rates, non-GL costs and its own calendar, and this is where the price
 * is paid.
 *
 * **The statement is live and the runs are stored, and both are on the page on purpose.** The live figure is what a
 * book-keeper investigating this morning needs. The stored runs are what §3.4's argument about control totals is for: "a
 * reconciliation computed later from live data cannot tell you what the figures were on the day somebody signed the
 * certificate." A page with only the live figure could not show that March was accepted, by whom, or why.
 *
 * **The causes are the page**, not an appendix to it. §4.2: the drill-down by cause "is what makes it a tool rather than
 * a number". A difference with no causes attached is a figure somebody screenshots and argues about for a fortnight.
 *
 * **And one section renders when empty**, which is §4.2's explicit instruction for it: unallocated purchase invoices.
 * §5 calls that "the single most likely silent failure in the module" — the accounts perfectly correct and the job
 * under-costed — so a section that disappeared when the list was empty would be indistinguishable from a section nobody
 * had built.
 */
class ReconciliationReport extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.construction.reconciliation-report';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $title = 'Reconciliation';

    protected static ?int $navigationSort = 60;

    public ?array $data = [];

    /**
     * Its own permission rather than a resource's, and `moduleIsAvailable()` explicitly — a page defining
     * `canAccess()` shadows the trait's, which is `docs/new-module-checklist.md` §11's trap.
     */
    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('ConstructionCostView') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill([
            // The earliest open period rather than the current month: that is the one that can still be fixed, and it is
            // the one a difference has been sitting in longest.
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
            HelpAction::make('construction-reconciliation', 'Reconciliation: Help'),

            /*
             * Storing a run is a read that keeps its answer, so it rides on `ConstructionCostView`.
             *
             * §4.3's point is that "a report nobody opens is not a control" — a permission that made this hard to run
             * would make §4's whole control optional. The sharp grant is *accepting* a difference, below.
             */
            Action::make('runReconciliation')
                ->label('Record this run')
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Record the reconciliation')
                ->modalDescription('Stores the figures and the causes as they stand now. A run is a dated statement '
                    .'about two ledgers, so recording one is how a period comes to have been proved — and a series of '
                    .'them is what says whether a difference is new.')
                ->schema([
                    Textarea::make('notes')->label('Note')->rows(2),
                ])
                ->action(function (array $data): void {
                    $run = app(ReconciliationService::class)
                        ->run($this->periodStart(), $data['notes'] ?? null);

                    Notification::make()
                        ->status($run->isBalanced() ? 'success' : 'warning')
                        ->title($run->isBalanced() ? 'Balanced' : 'Recorded, and it does not balance')
                        ->body($run->isBalanced()
                            ? $run->period_start->format('F Y').' reconciles.'
                            : 'Difference '.number_format((float) $run->difference, 2)
                                .'. Nothing has been adjusted — both ledgers stay as they are.')
                        ->send();
                }),

            /*
             * **§4.3's third mechanism, and the sharpest grant in this module because of what it is not.**
             *
             * It fixes nothing. §4.3's fourth mechanism is the promise attached to it: "a forced close never fudges the
             * ledger. No plug entry, no balancing figure. Both sides stay true and the difference stays visible in every
             * later period until the cause is fixed." What this records is a name against a decision to carry on, and it
             * is a control precisely because that is uncomfortable to sign.
             */
            Action::make('acceptDifference')
                ->label('Accept the difference')
                ->icon('heroicon-o-hand-raised')
                ->color('danger')
                ->visible(fn (): bool => (auth()->user()?->can('ConstructionPeriodForceClose') ?? false)
                    && ($run = $this->latestRun()) !== null
                    && $run->blocksClose())
                ->modalHeading('Accept an unexplained difference')
                ->modalDescription('This corrects nothing. Both ledgers stay exactly as they are and the difference '
                    .'stays visible in every later period until its cause is fixed. What this records is your name '
                    .'against a decision to close the month anyway.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Why')
                        ->required()
                        ->rows(3)
                        ->helperText('The only thing this stores. Whoever reads it in a year needs to know what was '
                            .'known at the time and what was decided.'),
                ])
                ->action(function (array $data): void {
                    $run = $this->latestRun();

                    if ($run === null) {
                        Notification::make()->warning()
                            ->title('Nothing to accept')
                            ->body('Record a run first — there is no stored figure to put a reason against.')
                            ->send();

                        return;
                    }

                    app(ReconciliationService::class)->accept($run, $data['reason']);

                    Notification::make()->success()
                        ->title('Difference accepted')
                        ->body('The month can be closed. Nothing was adjusted, and the difference is still there.')
                        ->send();
                }),
        ];
    }

    public function periodStart(): string
    {
        return $this->data['period_start'] ?? CostPeriod::startFor(now())->toDateString();
    }

    public function period(): ?CostPeriod
    {
        return CostPeriod::query()->starting($this->periodStart())->first();
    }

    /** §4.2's statement, computed live. */
    public function statement(): ReconciliationResult
    {
        return app(ReconciliationService::class)->compute($this->periodStart());
    }

    /** The most recent stored run for the period, which is what a close is judged against. */
    public function latestRun(): ?Reconciliation
    {
        return Reconciliation::latestFor($this->periodStart());
    }

    /**
     * The period's run history.
     *
     * Shown because a period balanced every month that broke in March is a different problem from one nobody has ever
     * proved, and only a series of rows can say which.
     *
     * @return \Illuminate\Support\Collection<int, Reconciliation>
     */
    public function history(): \Illuminate\Support\Collection
    {
        return Reconciliation::query()
            ->forPeriod($this->periodStart())
            ->orderByDesc('run_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }
}
