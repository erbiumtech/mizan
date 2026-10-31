<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntries\Tables;

use App\Filament\Support\PendingFirst;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        // Painted before it is filled.
        //
        // Without this the whole page waits on this table's query, its count and
        // its filters before a single pixel arrives; with it the shell and the
        // heading render immediately and the rows follow in a second request.
        // Applied to the long lists rather than to every table — on a table of
        // twenty rows it buys a round trip and nothing else.
        //
        // See docs/page-load-performance-plan.md.
        return $table
            ->deferLoading()
            ->defaultSort(PendingFirst::sort(JournalEntry::class, 'id', 'asc'))
            ->columns([
                TextColumn::make('entry_number')
                    ->label(__('Entry Number'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('entry_date')
                    ->label(__('Entry Date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('entry_type')
                    ->label(__('Entry Type'))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'info',
                        'pending_approval' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'posted' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('reference')
                    ->label(__('Reference'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('memo')
                    ->label(__('Memo'))
                    ->toggleable(),

                TextColumn::make('fiscalYear.name')
                    ->label(__('Fiscal Year')),

                TextColumn::make('creator.name')
                    ->label(__('Created By')),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                ...self::stateActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ...self::stateBulkActions(),
                ]),
            ]);
    }

    /**
     * Per-record workflow actions — parity with Nova Submit/Approve/Reject/Post/Reverse.
     *
     * @return array<Action>
     */
    protected static function stateActions(): array
    {
        return [
            Action::make('submit')
                ->label(__('Submit for Approval'))
                ->visible(fn (JournalEntry $record): bool => auth()->user()?->can('submit', $record) ?? false)
                ->requiresConfirmation()
                ->action(fn (JournalEntry $record) => self::run(fn (JournalEntryService $s) => $s->submitForApproval($record), 'Submitted for approval.')),

            Action::make('approve')
                ->label(__('Approve'))
                ->visible(fn (JournalEntry $record): bool => auth()->user()?->can('approve', $record) ?? false)
                ->requiresConfirmation()
                ->action(fn (JournalEntry $record) => self::run(fn (JournalEntryService $s) => $s->approve($record, auth()->user()), 'Journal entry approved.')),

            Action::make('reject')
                ->label(__('Reject'))
                ->visible(fn (JournalEntry $record): bool => auth()->user()?->can('reject', $record) ?? false)
                ->schema([
                    Textarea::make('reason')->label(__('Reason'))->required(),
                ])
                ->action(fn (array $data, JournalEntry $record) => self::run(fn (JournalEntryService $s) => $s->reject($record, auth()->user(), $data['reason']), 'Journal entry rejected.')),

            Action::make('post')
                ->label(__('Post Entry'))
                ->visible(fn (JournalEntry $record): bool => auth()->user()?->can('post', $record) ?? false)
                ->requiresConfirmation()
                ->modalDescription('Posting will update account balances. This cannot be undone (only reversed). Continue?')
                ->action(fn (JournalEntry $record) => self::run(fn (JournalEntryService $s) => $s->post($record), 'Journal entry posted to the ledger.')),

            Action::make('reverse')
                ->label(__('Reverse Entry'))
                ->visible(fn (JournalEntry $record): bool => auth()->user()?->can('reverse', $record) ?? false)
                ->requiresConfirmation()
                ->modalDescription('This will create and post a mirrored reversing entry. Continue?')
                ->action(fn (JournalEntry $record) => self::run(fn (JournalEntryService $s) => $s->reverse($record, auth()->user()), 'Reversing entry created and posted.')),
        ];
    }

    /**
     * Bulk equivalents — run over each selected entry.
     *
     * @return array<BulkAction>
     */
    protected static function stateBulkActions(): array
    {
        return [
            BulkAction::make('submitBulk')
                ->label(__('Submit for Approval'))
                ->visible(fn (): bool => auth()->user()?->can('JournalEntrySubmit') ?? false)
                ->requiresConfirmation()
                ->action(fn (Collection $records) => self::runBulk($records, fn (JournalEntryService $s, JournalEntry $e) => $s->submitForApproval($e), 'Submitted for approval.')),

            BulkAction::make('approveBulk')
                ->label(__('Approve'))
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryApprove') ?? false)
                ->requiresConfirmation()
                ->action(fn (Collection $records) => self::runBulk($records, fn (JournalEntryService $s, JournalEntry $e) => $s->approve($e, auth()->user()), 'Journal entries approved.')),

            BulkAction::make('rejectBulk')
                ->label(__('Reject'))
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryReject') ?? false)
                ->schema([
                    Textarea::make('reason')->label(__('Reason'))->required(),
                ])
                ->action(fn (array $data, Collection $records) => self::runBulk($records, fn (JournalEntryService $s, JournalEntry $e) => $s->reject($e, auth()->user(), $data['reason']), 'Journal entries rejected.')),

            BulkAction::make('postBulk')
                ->label(__('Post Entry'))
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryPost') ?? false)
                ->requiresConfirmation()
                ->modalDescription('Posting will update account balances. This cannot be undone (only reversed). Continue?')
                ->action(fn (Collection $records) => self::runBulk($records, fn (JournalEntryService $s, JournalEntry $e) => $s->post($e), 'Journal entries posted to the ledger.')),

            BulkAction::make('reverseBulk')
                ->label(__('Reverse Entry'))
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryReverse') ?? false)
                ->requiresConfirmation()
                ->modalDescription('This will create and post a mirrored reversing entry. Continue?')
                ->action(fn (Collection $records) => self::runBulk($records, fn (JournalEntryService $s, JournalEntry $e) => $s->reverse($e, auth()->user()), 'Reversing entries created and posted.')),
        ];
    }

    /**
     * Run a single-entry workflow operation, surfacing service validation errors as notifications.
     */
    protected static function run(callable $op, string $success): void
    {
        try {
            $op(app(JournalEntryService::class));
            Notification::make()->title($success)->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function runBulk(Collection $records, callable $op, string $success): void
    {
        $service = app(JournalEntryService::class);
        try {
            foreach ($records as $record) {
                $op($service, $record);
            }
            Notification::make()->title($success)->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
