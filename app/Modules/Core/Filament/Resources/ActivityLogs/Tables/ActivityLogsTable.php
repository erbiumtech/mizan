<?php

namespace App\Modules\Core\Filament\Resources\ActivityLogs\Tables;

use App\Modules\Core\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
            /*
             * Filters — docs/saas-launch-plan.md §14.9.
             *
             * The audit log is a screen somebody opens with a question already in mind —
             * "who changed that payslip", "what happened on the 3rd" — and until now it
             * answered by making them page through everything. The platform panel's copy of
             * this table has had these since it was written.
             */
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Record')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('log_name')
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()),

                SelectFilter::make('event')
                    ->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),

                SelectFilter::make('causer_id')
                    ->label('Who')
                    ->searchable()
                    ->options(fn (): array => User::query()->inCurrentCompany()->orderBy('name')->pluck('name', 'id')->all()),

                Filter::make('when')
                    ->schema([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from): Builder => $q->whereDate('created_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until): Builder => $q->whereDate('created_at', '<=', $until)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'From '.$data['from'];
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Until '.$data['until'];
                        }

                        return $indicators;
                    }),
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
