<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Tables;

use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Services\CostLedger;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cost register.
 *
 * **Reversing is a row action rather than a bulk one, on purpose.** Backing out a whole month's allocation is a
 * batch operation and belongs to `CostLedger::reverseBatch()`, which reverses a named batch rather than whatever
 * happens to be selected on screen — §3.2's point that a reversal re-deriving its rows from a predicate is one
 * that will eventually find a hundred and ninety-nine of two hundred and say nothing.
 */
class CostEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('incurred_on')
                    ->label('Incurred')
                    ->date()
                    ->sortable(),

                TextColumn::make('posting_period')
                    ->label('Period')
                    ->formatStateUsing(fn ($state): string => $state->format('M Y'))
                    ->description(fn (CostEntry $record): ?string => $record->is_late_for_period ? 'late' : null)
                    ->sortable(),

                TextColumn::make('job.code')
                    ->label('Job')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('costCode.code')
                    ->label('Code')
                    ->description(fn (CostEntry $record): ?string => $record->costCode?->name)
                    ->searchable(),

                TextColumn::make('cost_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'labour' => 'info',
                        'material' => 'success',
                        'plant' => 'warning',
                        'subcontract' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('quantity')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->alignEnd()
                    // A reversal reads as a credit, which is what it is.
                    ->color(fn (CostEntry $record): string => (float) $record->amount < 0 ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('gl_treatment')
                    ->label('GL')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CostEntry::GL_PENDING => 'warning',
                        CostEntry::GL_MEMO => 'gray',
                        default => 'success',
                    })
                    ->toggleable(),

                IconColumn::make('reversed_by_id')
                    ->label('Reversed')
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('description')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('cost_type')
                    ->label('Cost type')
                    ->options([
                        'labour' => 'Labour',
                        'material' => 'Material',
                        'plant' => 'Plant',
                        'subcontract' => 'Subcontract',
                        'other' => 'Other',
                    ])
                    ->multiple(),

                SelectFilter::make('gl_treatment')
                    ->label('GL treatment')
                    ->options([
                        CostEntry::GL_PENDING => 'Pending',
                        CostEntry::GL_MIRRORED => 'Mirrored',
                        CostEntry::GL_POSTED => 'Posted',
                        CostEntry::GL_MEMO => 'Memo',
                    ]),

                // §3.4's Late Costs report, as a filter on the register somebody is already looking at.
                Filter::make('is_late_for_period')
                    ->label('Late costs')
                    ->query(fn (Builder $query) => $query->where('is_late_for_period', true))
                    ->toggle(),

                Filter::make('unreversed')
                    ->label('Not reversed')
                    ->query(fn (Builder $query) => $query->whereNull('reversed_by_id'))
                    ->toggle(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make()
                    // Hidden rather than disabled once the row hardens: an edit button that always refuses is
                    // a button people click twice and then complain about.
                    ->visible(fn (CostEntry $record): bool => $record->isEditable()),

                Action::make('reverse')
                    ->label('Reverse')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reverse this cost')
                    ->modalDescription('A reversal is a new negative row pointing back at this one. Both stay on the ledger, which is what lets somebody see what was thought at the time and when it changed.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(2)
                            ->required()
                            ->helperText('Read months later by whoever asks why the figure moved.'),
                    ])
                    ->visible(fn (CostEntry $record): bool => auth()->user()?->can('reverse', $record) ?? false)
                    ->action(function (CostEntry $record, array $data): void {
                        try {
                            app(CostLedger::class)->reverse($record, $data['reason']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Reversed.')
                            ->body('The original stays on the ledger with the reversal against it.')
                            ->send();
                    }),
            ]);
    }
}
