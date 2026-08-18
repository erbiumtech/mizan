<?php

namespace App\Modules\Construction\Filament\Resources\Jobs\Tables;

use App\Modules\Construction\Models\Job;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The job list.
 *
 * Sorted by code rather than by date, because a job number is how everyone on site refers to one and the
 * list is used to find a known job far more often than to browse.
 */
class JobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Job no.')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    ->placeholder('Not awarded')
                    ->toggleable(),

                TextColumn::make('site_city')
                    ->label('Site')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'in_progress' => 'success',
                        'suspended', 'cancelled', 'lost' => 'danger',
                        'tender' => 'gray',
                        'closed' => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->sortable(),

                TextColumn::make('contract_standard')
                    ->label('Standard')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Job::STANDARD_FIDIC => 'FIDIC',
                        Job::STANDARD_AIA => 'AIA',
                        default => 'Custom',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('contract_sum')
                    ->label('Contract sum')
                    ->money('PKR')
                    ->alignEnd()
                    ->placeholder('—')
                    ->sortable(),

                // The date in force, so a job with an approved extension of time does not read as late.
                TextColumn::make('planned_completion_date')
                    ->label('Completion')
                    ->state(fn (Job $record) => $record->completionDate())
                    ->date()
                    ->placeholder('—')
                    ->description(fn (Job $record): ?string => $record->revised_completion_date ? 'revised' : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'tender' => 'Tender',
                        'awarded' => 'Awarded',
                        'mobilising' => 'Mobilising',
                        'in_progress' => 'In progress',
                        'suspended' => 'Suspended',
                        'substantial_completion' => 'Substantial completion',
                        'defects_liability' => 'Defects liability',
                        'final_account' => 'Final account',
                        'closed' => 'Closed',
                        'cancelled' => 'Cancelled',
                        'lost' => 'Lost',
                    ])
                    ->multiple(),

                SelectFilter::make('nature')
                    ->options([
                        'building' => 'Building',
                        'civils' => 'Civils',
                        'infrastructure' => 'Infrastructure',
                        'fit_out' => 'Fit-out',
                        'mep' => 'MEP',
                        'marine' => 'Marine',
                        'other' => 'Other',
                    ]),

                // Default view is the jobs somebody is actually running: a contractor's closed and lost
                // list grows forever and is not what the screen is for.
                TernaryFilter::make('live')
                    ->label('Live jobs')
                    ->placeholder('Live only')
                    ->trueLabel('Live only')
                    ->falseLabel('Closed, cancelled and lost')
                    ->queries(
                        true: fn ($query) => $query->live(),
                        false: fn ($query) => $query->whereIn('status', Job::DORMANT_STATUSES),
                        blank: fn ($query) => $query->live(),
                    ),
            ])
            ->defaultSort('code')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
