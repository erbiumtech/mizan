<?php

namespace App\Filament\Resources\BankStatements\Tables;

use App\Models\BankStatement;
use App\Services\BankReconciliationService;
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

class BankStatementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('account.name')
                    ->label('Bank Account')
                    ->sortable(),

                TextColumn::make('statement_date')
                    ->label('Statement Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('opening_balance')
                    ->label('Opening Balance')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('closing_balance')
                    ->label('Closing Balance')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'info',
                        'in_progress' => 'warning',
                        'completed' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (BankStatement $record): string => $record->lines()->count()
                        ? "{$record->matchedCount()} / {$record->lines()->count()} matched"
                        : '—'),

                TextColumn::make('completedBy.name')
                    ->label('Completed By')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('completed_at')
                    ->label('Completed At')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                ...self::reconciliationActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ...self::reconciliationBulkActions(),
                ]),
            ]);
    }

    /**
     * Per-record actions — parity with Nova ImportStatementLines / AutoMatchStatement / CompleteReconciliation.
     *
     * @return array<Action>
     */
    protected static function reconciliationActions(): array
    {
        return [
            Action::make('importLines')
                ->label('Import Lines (CSV)')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (BankStatement $record): bool => auth()->user()?->can('import', $record) ?? false)
                ->schema([
                    Textarea::make('csv')
                        ->label('CSV')
                        ->required()
                        ->helperText('One row per line: transaction_date,description,reference,amount (amount signed; negative for money out).'),
                ])
                ->action(fn (array $data, BankStatement $record) => self::run(function (BankReconciliationService $s) use ($data, $record) {
                    $rows = self::parseCsv($data['csv']);
                    if ($rows === []) {
                        throw new \InvalidArgumentException('No valid rows found.');
                    }
                    $s->import($rows, $record);

                    return count($rows) . ' line(s) imported.';
                }, 'Import Lines')),

            Action::make('autoMatch')
                ->label('Auto-Match')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->modalDescription('Auto-match unmatched statement lines against the ledger (exact amount + date within 3 days, or amount + reference)?')
                ->visible(fn (BankStatement $record): bool => auth()->user()?->can('match', $record) ?? false)
                ->action(fn (BankStatement $record) => self::run(fn (BankReconciliationService $s) => 'Auto-matched ' . $s->autoMatch($record) . ' line(s).', 'Auto-Match')),

            Action::make('completeReconciliation')
                ->label('Complete Reconciliation')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->modalDescription('Complete this reconciliation? All lines must be matched or excluded and the closing balance must equal the ledger balance. This locks the statement.')
                ->visible(fn (BankStatement $record): bool => auth()->user()?->can('complete', $record) ?? false)
                ->action(fn (BankStatement $record) => self::run(function (BankReconciliationService $s) use ($record) {
                    $s->complete($record, auth()->user());

                    return 'Reconciliation completed.';
                }, 'Complete Reconciliation')),
        ];
    }

    /**
     * Bulk equivalents — run over each selected statement.
     *
     * @return array<BulkAction>
     */
    protected static function reconciliationBulkActions(): array
    {
        return [
            BulkAction::make('importLinesBulk')
                ->label('Import Lines (CSV)')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->can('BankStatementImport') ?? false)
                ->schema([
                    Textarea::make('csv')
                        ->label('CSV')
                        ->required()
                        ->helperText('One row per line: transaction_date,description,reference,amount (amount signed; negative for money out).'),
                ])
                ->action(fn (array $data, Collection $records) => self::runBulk($records, function (BankReconciliationService $s, BankStatement $r) use ($data) {
                    $rows = self::parseCsv($data['csv']);
                    if ($rows === []) {
                        throw new \InvalidArgumentException('No valid rows found.');
                    }
                    $s->import($rows, $r);
                }, 'Import Lines')),

            BulkAction::make('autoMatchBulk')
                ->label('Auto-Match')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('BankStatementMatch') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (BankReconciliationService $s, BankStatement $r) => $s->autoMatch($r), 'Auto-Match')),

            BulkAction::make('completeReconciliationBulk')
                ->label('Complete Reconciliation')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('BankStatementComplete') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (BankReconciliationService $s, BankStatement $r) => $s->complete($r, auth()->user()), 'Complete Reconciliation')),
        ];
    }

    /**
     * Run a single-statement operation, surfacing service validation errors as notifications.
     * The callable returns a success message.
     */
    protected static function run(callable $op, string $label): void
    {
        try {
            $message = $op(app(BankReconciliationService::class));
            Notification::make()->title($message ?: "{$label}: processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (\Exception $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function runBulk(Collection $records, callable $op, string $label): void
    {
        $service = app(BankReconciliationService::class);
        $done = 0;
        try {
            foreach ($records as $record) {
                $op($service, $record);
                $done++;
            }
            Notification::make()->title("{$label}: {$done} statement(s) processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (\Exception $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /**
     * @return array<int, array<string, ?string>>
     */
    protected static function parseCsv(string $csv): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($csv)) as $raw) {
            $raw = trim($raw);

            if ($raw === '') {
                continue;
            }

            $cols = array_map('trim', str_getcsv($raw));

            if (strtolower($cols[0] ?? '') === 'transaction_date') {
                continue; // header row
            }

            $rows[] = [
                'transaction_date' => $cols[0] ?? null,
                'description' => $cols[1] ?? null,
                'reference' => $cols[2] ?? null,
                'amount' => $cols[3] ?? null,
            ];
        }

        return $rows;
    }
}
