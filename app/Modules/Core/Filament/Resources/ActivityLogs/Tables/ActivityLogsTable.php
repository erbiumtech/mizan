<?php

namespace App\Modules\Core\Filament\Resources\ActivityLogs\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('log_name')
                    ->label('Model')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('event')
                    ->label('Event')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                        default => (string) $state,
                    })
                    ->sortable(),

                TextColumn::make('causer')
                    ->label('Causer')
                    // Eager-loaded by ActivityLogResource::getEloquentQuery(); see there.
                    //
                    // Not sortable, and cannot be: `causer` is a morphTo with no
                    // column behind it, so Filament's default sort would order by
                    // a column named `causer` that does not exist. It carried
                    // ->sortable() until the eager-loading fix above went in, and
                    // clicking the header was a SQL error.
                    ->state(fn (Activity $record): string => $record->causer?->name ?? 'System'),

                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            /*
             * The whole row opens the entry in a modal.
             *
             * Two changes, and both are needed. `recordUrl(null)` stops the row linking to the view *page*
             * — a resource table points rows at that page whenever the resource has one, which is what was
             * navigating away. `recordAction` then gives the row something to do instead: the same view
             * action as the eye button, which on a list page renders the resource's infolist in a modal.
             *
             * The view page is deliberately left registered. It is what global search links to, and a
             * deep link to one audit entry is worth keeping — this changes how the *list* behaves, not
             * what the entry is reachable by.
             */
            ->recordUrl(null)
            ->recordAction('view')
            ->recordActions([
                // A modal, not a link. What makes that true is ListActivityLogs declining to supply the
                // default view-page URL — `->url(null)` here does nothing, for the reason set out there.
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
