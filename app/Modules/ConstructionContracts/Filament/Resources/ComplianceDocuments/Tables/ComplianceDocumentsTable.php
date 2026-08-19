<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Tables;

use App\Modules\ConstructionContracts\Models\ComplianceDocument;
use App\Modules\ConstructionContracts\Services\ComplianceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The compliance register.
 *
 * **The status column is computed on read**, which is the whole point: §12 calls a stored one the most dangerous silent
 * failure on the payable side, because a row saying `verified` with an expiry three months past pays a subcontractor
 * with no cover while the screen looks fine.
 *
 * **Days to expiry is signed.** "Expired 40 days ago" and "expires in 40 days" are different problems, and a column
 * clamped at zero loses the difference — the same reason `EmployeeDocument::daysUntilExpiry()` is signed.
 */
class ComplianceDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label('Document')
                    ->formatStateUsing(fn (ComplianceDocument $record): string => $record->label())
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contract.contract_number')
                    ->label('Contract')
                    // "Company-wide" rather than a dash: it is a fact about the document's scope, not a missing field.
                    ->placeholder('Company-wide')
                    ->description(fn (ComplianceDocument $record): ?string => $record->contract?->job?->code)
                    ->toggleable(),

                TextColumn::make('scope')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->toggleable(),

                TextColumn::make('reference')
                    ->label('Reference')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // Computed, never read from a column — see the class docblock.
                    ->state(fn (ComplianceDocument $record): string => str_replace('_', ' ', $record->statusOn()))
                    ->color(fn (ComplianceDocument $record): string => match ($record->statusOn()) {
                        ComplianceDocument::STATUS_VALID => 'success',
                        ComplianceDocument::STATUS_EXPIRING => 'warning',
                        ComplianceDocument::STATUS_EXPIRED => 'danger',
                        ComplianceDocument::STATUS_WAIVED => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('expires_on')
                    ->label('Expires')
                    ->date()
                    ->placeholder('No expiry')
                    ->description(function (ComplianceDocument $record): ?string {
                        $days = $record->daysUntilExpiry();

                        if ($days === null) {
                            return null;
                        }

                        // Signed: the two directions are different problems and the wording says which.
                        return $days < 0
                            ? abs($days).' days ago'
                            : 'in '.$days.' days';
                    })
                    ->sortable(),

                TextColumn::make('amount_covered')
                    ->label('Sum insured')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('verified_at')
                    ->label('Verified')
                    ->dateTime()
                    // Arriving and being read are two different facts, and this column is the second one.
                    ->placeholder('Not yet read')
                    ->toggleable(),

                TextColumn::make('waiver_reason')
                    ->label('Waived because')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('contract_id')
                    ->label('Contract')
                    ->relationship('contract', 'contract_number')
                    ->searchable(),

                SelectFilter::make('scope')
                    ->options([
                        ComplianceDocument::SCOPE_COMPANY => 'Company',
                        ComplianceDocument::SCOPE_CONTRACT => 'Contract',
                        ComplianceDocument::SCOPE_PERIOD => 'Period',
                    ]),

                // What somebody chases: expiring inside two months, or already gone.
                Filter::make('needs_attention')
                    ->label('Expiring or expired')
                    ->query(fn (Builder $query) => $query->needingAttention())
                    ->toggle(),

                Filter::make('unverified')
                    ->label('Received, not read')
                    ->query(fn (Builder $query) => $query->whereNull('verified_at'))
                    ->toggle(),
            ])
            ->defaultSort('expires_on')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ComplianceDocument $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirm you have read it')
                    ->modalDescription('Verifying says somebody has looked at the document, not merely that it arrived. Until then it does not satisfy a requirement.')
                    ->visible(fn (ComplianceDocument $record): bool => auth()->user()?->can('verify', $record) ?? false)
                    ->action(fn (ComplianceDocument $record) => static::run(
                        fn () => app(ComplianceService::class)->verify($record),
                        'Verified.',
                        'It now satisfies its requirement while it remains in date.',
                    )),

                Action::make('waive')
                    ->label('Waive')
                    ->icon('heroicon-o-hand-raised')
                    ->color('warning')
                    ->modalHeading('Waive this requirement')
                    ->modalDescription('Says this subcontractor will not produce it and the company accepts that. It is a standing decision — unlike a certificate override, which is about one payment.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(3)
                            ->required()
                            ->helperText('Read by whoever asks why payments went out without it.'),
                    ])
                    ->visible(fn (ComplianceDocument $record): bool => auth()->user()?->can('waive', $record) ?? false)
                    ->action(fn (ComplianceDocument $record, array $data) => static::run(
                        fn () => app(ComplianceService::class)->waive($record, $data['reason']),
                        'Waived.',
                        'The reason is on the record.',
                    )),
            ]);
    }

    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
