<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Tables;

use App\Modules\ConstructionCosting\Models\LabourRate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rate ladder, made readable.
 *
 * **Two columns carry this screen.** *Applies to* says what a row is scoped to, because five rows that all look like
 * rates are unreadable without it; and *In force* says whether a row is the one being used today, because a register
 * of dated rows where nothing marks the current one is a register people misread. §7.2's ladder is the ordering, and
 * the default sort follows it — most specific first — so the row that would win sits at the top.
 */
class LabourRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('applies_to')
                    ->label('Applies to')
                    ->getStateUsing(fn (LabourRate $record): string => $record->appliesTo())
                    ->description(fn (LabourRate $record): string => match ($record->tier()) {
                        LabourRate::TIER_JOB_AND_TRADE => 'That trade, on that job',
                        LabourRate::TIER_JOB => 'Everybody on that job',
                        LabourRate::TIER_PERSON => 'One person, anywhere',
                        LabourRate::TIER_TRADE => 'That trade, any job',
                        default => 'Everybody, unless something more specific applies',
                    })
                    ->wrap(),

                TextColumn::make('cost_rate_per_hour')
                    ->label('Per hour')
                    ->money('PKR')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('overtime_multiplier')
                    ->label('OT ×')
                    ->alignEnd()
                    // A dash is the honest rendering of a null here: it means "whatever the next rate down says",
                    // not "no overtime".
                    ->placeholder('inherited')
                    ->toggleable(),

                TextColumn::make('burden_percent')
                    ->label('Burden %')
                    ->alignEnd()
                    ->placeholder('inherited')
                    ->toggleable(),

                TextColumn::make('effective_from')
                    ->label('From')
                    ->date()
                    ->sortable(),

                TextColumn::make('effective_to')
                    ->label('Until')
                    ->date()
                    ->placeholder('Further notice')
                    ->sortable(),

                TextColumn::make('in_force')
                    ->label('In force')
                    ->badge()
                    ->getStateUsing(fn (LabourRate $record): string => match (true) {
                        $record->effective_from?->isFuture() => 'From '.$record->effective_from->format('d M'),
                        $record->effective_to !== null && $record->effective_to->isPast() => 'Ended',
                        default => 'Today',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Today' => 'success',
                        'Ended' => 'gray',
                        default => 'info',
                    }),

                TextColumn::make('notes')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('job_id')
                    ->label('Job')
                    ->relationship('job', 'code')
                    ->searchable(),

                SelectFilter::make('trade_id')
                    ->label('Trade')
                    ->relationship('trade', 'name')
                    ->searchable(),

                Filter::make('in_force')
                    ->label('In force today')
                    ->query(fn (Builder $query) => $query->inForceOn(now()->toDateString()))
                    ->toggle(),

                Filter::make('company_default')
                    ->label('Company defaults only')
                    ->query(fn (Builder $query) => $query
                        ->whereNull('job_id')->whereNull('trade_id')
                        ->whereNull('worker_id')->whereNull('employee_id'))
                    ->toggle(),
            ])
            // Most specific first, so the row that would win a resolution is the row at the top. Sorted on the scope
            // columns rather than on `tier()`, which is computed and cannot be an `ORDER BY`.
            ->defaultSort('effective_from', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (LabourRate $record): bool => auth()->user()?->can('update', $record) ?? false),

                DeleteAction::make()
                    // Only a rate that has not started: once one has been in force, a labour record has probably
                    // frozen its figure and a deleted row leaves that snapshot with nothing to explain it.
                    ->visible(fn (LabourRate $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No rates set')
            ->emptyStateDescription('Until a rate exists, labour cannot be costed at all — nothing here guesses one, because a week of labour costing 0.00 looks like a healthy figure and is not. Start with a company default, then add trades.');
    }
}
