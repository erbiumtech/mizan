<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\CostPeriods\Tables;

use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\GlPosting;
use App\Modules\ConstructionCosting\Services\AccrualService;
use App\Modules\ConstructionCosting\Services\ConstructionGlPostingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CostPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                TextColumn::make('period_start')
                    ->label('Month')
                    ->formatStateUsing(fn (CostPeriod $record): string => $record->label())
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CostPeriod::STATUS_OPEN => 'warning',
                        CostPeriod::STATUS_RECONCILED => 'success',
                        default => 'gray',
                    }),

                /*
                 * **What the period still owes the general ledger**, which is the number this screen exists for.
                 *
                 * A single indexed count over `(posting_period, gl_treatment)`, which the cost-entry table already
                 * carries. §7.3's failure is what it warns about: burden charged and never absorbed makes job cost
                 * exceed GL cost by exactly that figure, growing every month, with no error anywhere.
                 */
                TextColumn::make('pending_count')
                    ->label('Awaiting the GL')
                    ->state(fn (CostPeriod $record): int => CostEntry::query()
                        ->awaitingGl()
                        ->inPeriod($record->period_start->toDateString())
                        ->count())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success')
                    ->description(fn (CostPeriod $record): ?string => CostEntry::query()
                        ->awaitingGl()
                        ->inPeriod($record->period_start->toDateString())
                        ->count() > 0 ? 'entries with no GL side yet' : null),

                TextColumn::make('posted')
                    ->label('Posted')
                    ->state(fn (CostPeriod $record): string => ($runs = GlPosting::query()
                        ->forPeriod($record->period_start->toDateString())
                        ->live()
                        ->count()) === 0
                            ? '—'
                            : $runs.' run'.($runs === 1 ? '' : 's')),

                TextColumn::make('difference')
                    ->label('Difference at close')
                    // Null until the period was closed, and an em dash rather than 0.00: a zero here would say the two
                    // ledgers were proved to agree, which is a different fact from nobody having checked.
                    ->placeholder('—')
                    ->numeric(decimalPlaces: 2)
                    ->color(fn (?string $state): string => $state !== null && abs((float) $state) >= 0.01
                        ? 'danger'
                        : 'gray'),

                TextColumn::make('closed_at')
                    ->label('Closed')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    CostPeriod::STATUS_OPEN => 'Open',
                    CostPeriod::STATUS_CLOSED => 'Closed',
                    CostPeriod::STATUS_RECONCILED => 'Reconciled',
                ]),
            ])
            ->recordActions([
                /*
                 * **§4.5's month-open, and it is deliberately the first action on the row.**
                 *
                 * "The reversal belongs to period *open* rather than period close", and §4.5 says why in terms of its
                 * own failure mode: if the reversal does not run, "the accrual and the real invoice both sit in the
                 * ledger and the job costs double for a month". Attached to opening the month it runs before anybody
                 * looks at the figures. Attached to closing it, it runs after everybody has.
                 *
                 * Idempotent, so running it twice is safe: the reversal finds nothing outstanding the second time, and
                 * the re-accrual is a fresh computation from today's facts rather than an increment.
                 */
                Action::make('rollAccruals')
                    ->label('Roll accruals into this month')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (CostPeriod $record): string => 'Open '.$record->label())
                    ->modalDescription('Reverses every accrual still standing from an earlier month, then re-raises '
                        .'whatever is still outstanding from today\'s facts: goods received and not invoiced, and '
                        .'subcontract work done and not certified. Safe to run again — the second run finds nothing to '
                        .'reverse and recomputes rather than adds.')
                    ->visible(fn (CostPeriod $record): bool => $record->isOpen()
                        && (auth()->user()?->can('ConstructionCostCreate') ?? false))
                    ->action(function (CostPeriod $record): void {
                        $run = app(AccrualService::class)->open($record->period_start->toDateString());

                        Notification::make()
                            ->success()
                            ->title('Opened '.$record->label())
                            ->body($run->describe())
                            ->persistent()
                            ->send();
                    }),

                /*
                 * **The preview, and it is a separate action from the posting for a reason.**
                 *
                 * This is the one act in the module that writes into the general ledger. Somebody should be able to see
                 * the lines, the accounts and — especially — what is *not* going to post before any of it exists.
                 */
                Action::make('previewPosting')
                    ->label('Preview posting')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (CostPeriod $record): bool => $record->isOpen()
                        && (auth()->user()?->can('ConstructionCostView') ?? false))
                    ->modalHeading(fn (CostPeriod $record): string => 'What '.$record->label().' would post')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalDescription(fn (CostPeriod $record): string => app(ConstructionGlPostingService::class)
                        ->plan($record->period_start->toDateString())
                        ->describe()),

                Action::make('post')
                    ->label('Post to the general ledger')
                    ->icon('heroicon-o-book-open')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(fn (CostPeriod $record): string => 'Post '.$record->label())
                    // The plan in the confirmation, absence included. §4.1's whole approach depends on a summary
                    // posting being explicable, and this is the last moment it is cheap to check.
                    ->modalDescription(fn (CostPeriod $record): string => app(ConstructionGlPostingService::class)
                        ->plan($record->period_start->toDateString())
                        ->describe())
                    ->visible(fn (CostPeriod $record): bool => $record->isOpen()
                        && (auth()->user()?->can('ConstructionGlPost') ?? false))
                    ->schema([
                        Textarea::make('notes')
                            ->label('Note')
                            ->rows(2)
                            ->helperText('Optional, and kept on the posting run rather than in the journal memo.'),
                    ])
                    ->action(function (CostPeriod $record, array $data): void {
                        $posting = app(ConstructionGlPostingService::class)
                            ->post($record->period_start->toDateString(), $data['notes'] ?? null);

                        Notification::make()
                            ->success()
                            ->title('Posted '.$record->label())
                            ->body(number_format((float) $posting->total_amount, 2).' across '
                                .$posting->line_count.' journal line(s) from '.$posting->entry_count.' cost entries.')
                            ->send();
                    }),

                Action::make('reversePosting')
                    ->label('Reverse the last posting')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (CostPeriod $record): bool => (auth()->user()?->can('ConstructionGlPost') ?? false)
                        && static::lastPosting($record) !== null)
                    ->modalHeading('Reverse a posting')
                    ->modalDescription('A reversing journal, not an unposting — the accounts are what somebody has '
                        .'already read, and deleting a line they read is worse than showing them the line that '
                        .'cancelled it. The cost entries go back to awaiting the GL, because that is what they are.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->required()
                            ->rows(2)
                            ->helperText('What this backs out is a figure in the accounts somebody has already read.'),
                    ])
                    ->action(function (CostPeriod $record, array $data): void {
                        $posting = static::lastPosting($record);

                        if ($posting === null) {
                            Notification::make()->warning()
                                ->title('Nothing to reverse')
                                ->body('No live posting run remains for '.$record->label().'.')
                                ->send();

                            return;
                        }

                        app(ConstructionGlPostingService::class)->reverse($posting, $data['reason']);

                        Notification::make()->success()
                            ->title('Reversed')
                            ->body($posting->displayName().' has been backed out of the accounts, and its cost entries '
                                .'are awaiting the general ledger again.')
                            ->send();
                    }),
            ]);
    }

    /**
     * The most recent posting run for a period that has not itself been reversed.
     *
     * `whereDoesntHave` rather than a flag, because "has this been reversed" is a fact about whether another row points
     * at it — which is §3.2's argument for `reversed_batch_id` being a column and not a boolean.
     */
    private static function lastPosting(CostPeriod $period): ?GlPosting
    {
        return GlPosting::query()
            ->forPeriod($period->period_start->toDateString())
            ->live()
            ->whereDoesntHave('reversals')
            ->orderByDesc('id')
            ->first();
    }
}
