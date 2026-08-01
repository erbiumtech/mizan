<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntries\Tables;

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
        return $table
            ->columns([
                TextColumn::make('entry_number')
                    ->label('Entry Number')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('entry_date')
                    ->label('Entry Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('entry_type')
                    ->label('Entry Type')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                TextColumn::make('status')
                    ->label('Status')
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
                    ->label('Reference')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('memo')
                    ->label('Memo')
                    ->toggleable(),

                TextColumn::make('fiscalYear.name')
                    ->label('Fiscal Year'),

                TextColumn::make('creator.name')
                    ->label('Created By'),
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
                ->label('Submit for Approval')
                ->visible(fn (JournalEntry $record): bool => auth()->user()?->can('submit', $record) ?? false)
                ->requiresConfirmation()
                ->action(fn (JournalEntry $record) => self::run(fn (JournalEntryService $s) => $s->submitForApproval($record), 'Submitted for approval.')),

            Action::make('approve')
                ->label('Approve')
                ->visible(fn (JournalEntry $record): bool => auth()->user()?->can('approve', $record) ?? false)
                ->requiresConfirmation()
                ->action(fn (JournalEntry $record) => self::run(fn (JournalEntryService $s) => $s->approve($record, auth()->user()), 'Journal entry approved.')),

            Action::make('reject')
                ->label('Reject')
                ->visible(fn (JournalEntry $record): bool => auth()->user()?->can('reject', $record) ?? false)
                ->schema([
                    Textarea::make('reason')->label('Reason')->required(),
                ])
                ->action(fn (array $data, JournalEntry $record) => self::run(fn (JournalEntryService $s) => $s->reject($record, auth()->user(), $data['reason']), 'Journal entry rejected.')),

            Action::make('post')
                ->label('Post Entry')
                ->visible(fn (JournalEntry $record): bool => auth()->user()?->can('post', $record) ?? false)
                ->requiresConfirmation()
                ->modalDescription('Posting will update account balances. This cannot be undone (only reversed). Continue?')
                ->action(fn (JournalEntry $record) => self::run(fn (JournalEntryService $s) => $s->post($record), 'Journal entry posted to the ledger.')),

            Action::make('reverse')
                ->label('Reverse Entry')
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
                ->label('Submit for Approval')
                ->visible(fn (): bool => auth()->user()?->can('JournalEntrySubmit') ?? false)
                ->requiresConfirmation()
                ->action(fn (Collection $records) => self::runBulk($records, fn (JournalEntryService $s, JournalEntry $e) => $s->submitForApproval($e), 'Submitted for approval.')),

            BulkAction::make('approveBulk')
                ->label('Approve')
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryApprove') ?? false)
                ->requiresConfirmation()
                ->action(fn (Collection $records) => self::runBulk($records, fn (JournalEntryService $s, JournalEntry $e) => $s->approve($e, auth()->user()), 'Journal entries approved.')),

            BulkAction::make('rejectBulk')
                ->label('Reject')
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryReject') ?? false)
                ->schema([
                    Textarea::make('reason')->label('Reason')->required(),
                ])
                ->action(fn (array $data, Collection $records) => self::runBulk($records, fn (JournalEntryService $s, JournalEntry $e) => $s->reject($e, auth()->user(), $data['reason']), 'Journal entries rejected.')),

            BulkAction::make('postBulk')
                ->label('Post Entry')
                ->visible(fn (): bool => auth()->user()?->can('JournalEntryPost') ?? false)
                ->requiresConfirmation()
                ->modalDescription('Posting will update account balances. This cannot be undone (only reversed). Continue?')
                ->action(fn (Collection $records) => self::runBulk($records, fn (JournalEntryService $s, JournalEntry $e) => $s->post($e), 'Journal entries posted to the ledger.')),

            BulkAction::make('reverseBulk')
                ->label('Reverse Entry')
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
