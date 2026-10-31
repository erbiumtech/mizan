<?php

namespace App\Modules\Work\Filament\Resources\IntegrationConnections\RelationManagers;

use App\Modules\Work\Models\IntegrationEvent;
use App\Modules\Work\Services\IntegrationEvents;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the provider has delivered — §8.3.
 *
 * Kept whole so a failed one can be replayed once whatever failed is fixed: an identity matched,
 * a bug shipped. The failure reason is in the row, because "it failed" without the why is a row
 * nobody can act on.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Deliveries';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('provider_event_id')
            ->columns([
                TextColumn::make('received_at')->label('Received')->dateTime('d M H:i:s')->sortable(),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('provider_event_id')->label('Delivery id')->limit(18)->copyable(),
                TextColumn::make('processed_at')
                    ->label('State')
                    ->badge()
                    ->state(fn (IntegrationEvent $record): string => match (true) {
                        $record->processed_at !== null => 'Processed',
                        $record->failed_at !== null => 'Failed',
                        default => 'Queued',
                    })
                    ->color(fn (IntegrationEvent $record): string => match (true) {
                        $record->processed_at !== null => 'success',
                        $record->failed_at !== null => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('failure')->placeholder('—')->wrap()->limit(120),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                Filter::make('failed')->label('Failed only')->query(fn (Builder $q) => $q->whereNotNull('failed_at')),
            ])
            ->recordActions([
                Action::make('replay')
                    ->label('Replay')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (IntegrationEvent $record): bool => $record->failed_at !== null
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (IntegrationEvent $record): void {
                        app(IntegrationEvents::class)->replay($record);
                        Notification::make()->success()->title('Queued again.')->send();
                    }),
            ]);
    }
}
