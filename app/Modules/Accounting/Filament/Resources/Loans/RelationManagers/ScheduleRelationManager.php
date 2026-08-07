<?php

namespace App\Modules\Accounting\Filament\Resources\Loans\RelationManagers;

use App\Modules\Accounting\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Modules\Accounting\Models\Loan;
use App\Modules\Accounting\Models\LoanInstalment;
use App\Modules\Accounting\Services\LoanService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The amortisation table, and the button that turns a row into a journal entry.
 *
 * Read-only apart from that button. The schedule is generated from the loan's
 * terms as a whole; editing one row of it would mean a table whose closing
 * balance no longer follows from its own arithmetic.
 */
class ScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'instalments';

    protected static ?string $title = 'Schedule';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('due_on')
                    ->label('Due')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('opening_balance')
                    ->label('Owed before')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payment')
                    ->label('Instalment')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('interest')
                    ->label('Interest')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->color('danger'),

                TextColumn::make('principal')
                    ->label('Principal')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->color('success'),

                TextColumn::make('closing_balance')
                    ->label('Owed after')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                IconColumn::make('recorded')
                    ->label('Recorded')
                    ->state(fn (LoanInstalment $record): bool => $record->isRecorded())
                    ->boolean(),

                TextColumn::make('journalEntry.entry_number')
                    ->label('Entry')
                    ->placeholder('—')
                    ->url(fn (LoanInstalment $record): ?string => $record->journal_entry_id
                        ? JournalEntryResource::getUrl('edit', ['record' => $record->journal_entry_id])
                        : null),
            ])
            ->filters([
                TernaryFilter::make('recorded')
                    ->label('Recorded')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('journal_entry_id'),
                        false: fn ($query) => $query->whereNull('journal_entry_id'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                Action::make('record')
                    ->label('Record')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->visible(fn (LoanInstalment $record): bool => ! $record->isRecorded()
                        && auth()->user()?->can('record', $record->loan))
                    ->schema([
                        DatePicker::make('date')
                            ->label('Paid on')
                            ->native(false)
                            ->default(fn (LoanInstalment $record) => $record->due_on)
                            ->required()
                            ->helperText('The instalment date unless it actually went out on another day.'),
                    ])
                    ->modalHeading('Record this instalment')
                    ->modalDescription('Raises a draft journal entry: the principal against the loan, the interest as a cost, and the whole payment out of the bank. It still needs approving and posting.')
                    ->action(function (LoanInstalment $record, array $data): void {
                        try {
                            $entry = app(LoanService::class)->recordInstalment($record, $data['date'] ?? null);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not record it')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title("Draft {$entry->entry_number} raised")
                            ->body('Find it under Journal Entries to submit and post.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('number')
            ->paginated([12, 24, 60, 'all'])
            ->defaultPaginationPageOption(12)
            ->emptyStateHeading('No schedule')
            ->emptyStateDescription('Save the loan and its instalments are worked out automatically.');
    }

    /** The schedule is generated, never authored. */
    public function canCreate(): bool
    {
        return false;
    }

    protected function canDeleteAny(): bool
    {
        return false;
    }

    public function getTableRecordKey($record): string
    {
        return (string) $record->getKey();
    }

    protected function getTableHeading(): string
    {
        /** @var Loan $loan */
        $loan = $this->getOwnerRecord();

        return sprintf(
            'Schedule — %s a month, %s interest over the whole term',
            number_format((float) ($loan->instalments()->first()->payment ?? 0), 2),
            number_format($loan->totalInterest(), 2),
        );
    }
}
