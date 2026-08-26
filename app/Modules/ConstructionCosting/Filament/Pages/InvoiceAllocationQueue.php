<?php

namespace App\Modules\ConstructionCosting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\InvoiceAllocation;
use App\Modules\ConstructionCosting\Services\InvoiceAllocationService;
use App\Modules\Invoicing\Models\Invoice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;
use RuntimeException;
use UnitEnum;

/**
 * Invoices awaiting allocation — `docs/construction-management-plan.md` §5.
 *
 * **This screen is one of the two structural answers to the most likely silent failure in the module.** §5 states it
 * plainly: "a purchase invoice can be posted with no allocation at all, and the general ledger is perfectly correct
 * while the job is under-costed". Every margin flatters, and no report shows an error. So the answer is not a validation
 * rule somebody can turn off but "an allocation queue that is a screen people work from" — and §4.2's reconciliation
 * section, "rendered even when empty", which is the other half.
 *
 * **Oldest first, and the total is on the page.** An invoice unallocated for two months is a job that has reported a
 * flattering margin for two months, and a queue with no total is a queue nobody can be asked about.
 *
 * **Empty is a result, not a blank page.** When there is nothing to allocate the page says so in words, because a
 * screen that looks broken when it is finished is a screen people stop opening.
 */
class InvoiceAllocationQueue extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.construction.invoice-allocation-queue';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $title = 'Invoices awaiting allocation';

    protected static ?int $navigationSort = 47;

    /**
     * Its own permission check as well as the module trait's.
     *
     * A page with its own `canAccess()` silently shadows the trait's — the trap `docs/new-module-checklist.md` §11
     * names — so `moduleIsAvailable()` is called explicitly. And the whole screen is absent without Invoicing, which
     * owns the invoices it lists.
     */
    public static function canAccess(): bool
    {
        return static::moduleIsAvailable()
            && modules()->enabled('invoicing')
            && (auth()->user()?->can('ConstructionCostView') ?? false);
    }

    /** @return array<int, \Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-invoice-allocations', 'Allocating invoices: Help')];
    }

    /** @return \Illuminate\Support\Collection<int, Invoice> */
    public function invoices(): \Illuminate\Support\Collection
    {
        return app(InvoiceAllocationService::class)->awaitingAllocation();
    }

    public function unallocatedOn(Invoice $invoice): float
    {
        return app(InvoiceAllocationService::class)->unallocated($invoice);
    }

    public function queueTotal(): float
    {
        return app(InvoiceAllocationService::class)->awaitingTotal();
    }

    /** How long it has been sitting there, which is the figure that makes somebody act. */
    public function ageInDays(Invoice $invoice): ?int
    {
        return $invoice->invoice_date?->diffInDays(now());
    }

    /**
     * Allocate one invoice across as many jobs and codes as it takes.
     *
     * A repeater rather than one job picker, because that is the whole reason allocations are a table: one line of
     * rebar routinely lands on two jobs and three codes, and the alternative — splitting the invoice line — makes the
     * document this application prints disagree with the one the supplier sent.
     */
    public function allocateAction(): Action
    {
        return Action::make('allocate')
            ->label('Allocate')
            ->icon('heroicon-o-scissors')
            ->modalHeading('Allocate this invoice to jobs and cost codes')
            ->modalDescription('Add a row per job and code. The invoice can be split as finely as it needs to be — that is why this is not a single job field on the invoice.')
            ->schema([
                Repeater::make('rows')
                    ->label('Allocations')
                    ->addActionLabel('Add another job or code')
                    ->minItems(1)
                    ->schema([
                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->live()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required(),

                        Select::make('cost_code_id')
                            ->label('Cost code')
                            ->options(fn (): array => CostCode::query()->where('is_leaf', true)->where('is_active', true)
                                ->orderBy('code')->get()
                                ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                                ->all())
                            ->searchable()
                            ->required(),

                        Select::make('commitment_line_id')
                            ->label('Against order line')
                            ->options(fn (): array => CommitmentLine::query()
                                ->committing()
                                ->with('commitment')
                                ->get()
                                ->mapWithKeys(fn (CommitmentLine $line): array => [
                                    $line->getKey() => ($line->commitment?->number ?? '—').' · '.$line->displayName(),
                                ])
                                ->all())
                            ->searchable()
                            // Optional: an invoice with no order still has to be costed. Where there is one, the
                            // invoice relieves only what was never received.
                            ->helperText('Optional. Where given, the invoice relieves whatever the delivery did not.'),

                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->helperText('Net of tax. A credit note is negative.'),
                    ])
                    ->columns(2),
            ])
            ->action(function (array $data, array $arguments): void {
                $invoice = Invoice::query()->with('lines')->findOrFail($arguments['invoice']);
                $service = app(InvoiceAllocationService::class);

                try {
                    foreach ($data['rows'] ?? [] as $row) {
                        $service->allocate(
                            $invoice,
                            Job::query()->findOrFail($row['job_id']),
                            CostCode::query()->findOrFail($row['cost_code_id']),
                            (float) $row['amount'],
                            ['commitment_line_id' => $row['commitment_line_id'] ?? null],
                        );
                    }
                } catch (InvalidArgumentException|RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Allocated.')
                    ->body($service->isFullyAllocated($invoice->refresh())
                        ? 'The invoice is fully allocated and has left the queue.'
                        : 'Part of it is still unallocated and it stays on the queue.')
                    ->send();
            });
    }

    /** Withdraw an allocation: the cost is reversed rather than deleted, and the commitment goes back. */
    public function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->label('Withdraw')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Withdraw this allocation')
            ->modalDescription('The cost entry is reversed rather than deleted — the job carried that cost for as long as the mistake stood, and a reversal is what says so.')
            ->action(function (array $arguments): void {
                app(InvoiceAllocationService::class)->deallocate(
                    InvoiceAllocation::query()->findOrFail($arguments['allocation']),
                    'Withdrawn from the allocation queue',
                );

                Notification::make()->success()->title('Withdrawn.')->send();
            });
    }

    /** @return array<int, InvoiceAllocation> */
    public function allocationsOn(Invoice $invoice): array
    {
        return InvoiceAllocation::query()
            /*
             * `commitmentLine.commitment` too, because the table prints the order number against every
             * allocation — "Unordered" where there is none. Two hops, so both are named: loading the line
             * without its commitment moves the N+1 one level down rather than removing it.
             */
            ->with(['job', 'costCode', 'commitmentLine.commitment'])
            ->where('invoice_id', $invoice->getKey())
            ->orderBy('id')
            ->get()
            ->all();
    }
}
