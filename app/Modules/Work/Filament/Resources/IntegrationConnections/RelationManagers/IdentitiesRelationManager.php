<?php

namespace App\Modules\Work\Filament\Resources\IntegrationConnections\RelationManagers;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Work\Models\IntegrationIdentity;
use App\Modules\Work\Services\Identities;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Who the provider's usernames are here — §8.5.
 *
 * Unmatched first, because they are the ones waiting on somebody. An administrator assigns; the
 * plan also lets a person claim from their own profile, and until that page exists the claim is
 * offered here to whoever is looking at their own username.
 */
class IdentitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'identities';

    protected static ?string $title = 'Identities';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('provider_username')
            ->columns([
                TextColumn::make('provider_username')->label('Username')->searchable()
                    ->formatStateUsing(fn (string $state): string => '@'.$state),
                TextColumn::make('email')->placeholder('—'),
                TextColumn::make('user_id')
                    ->label('Is')
                    ->badge()
                    ->state(fn (IntegrationIdentity $record): string => $record->user()?->name ?? 'Unmatched')
                    ->color(fn (IntegrationIdentity $record): string => $record->isMatched() ? 'success' : 'warning'),
                TextColumn::make('matched_how')->label('Matched by')->placeholder('—'),
                TextColumn::make('created_at')->label('First seen')->since(),
            ])
            ->defaultSort('user_id')
            ->filters([
                TernaryFilter::make('matched')
                    ->label('Matched')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('user_id'),
                        false: fn ($q) => $q->whereNull('user_id'),
                    ),
            ])
            ->recordActions([
                Action::make('assign')
                    ->label('Assign')
                    ->icon('heroicon-o-user')
                    ->visible(fn (IntegrationIdentity $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->schema([
                        Select::make('user_id')
                            ->label('Who this is')
                            ->options(fn (): array => (Company::current()?->users() ?? User::query())
                                ->orderBy('name')->pluck('name', 'users.id')->all())
                            ->searchable()
                            ->placeholder('Nobody — unmatch'),
                    ])
                    ->action(function (IntegrationIdentity $record, array $data): void {
                        $user = filled($data['user_id'] ?? null) ? User::query()->find($data['user_id']) : null;
                        app(Identities::class)->assign($record, $user);

                        Notification::make()->success()->title('@'.$record->provider_username.' is '.($user?->name ?? 'unmatched').'.')->send();
                    }),

                Action::make('claim')
                    ->label('That is me')
                    ->icon('heroicon-o-hand-raised')
                    ->color('gray')
                    ->visible(fn (IntegrationIdentity $record): bool => ! $record->isMatched() && auth()->check())
                    ->requiresConfirmation()
                    ->modalDescription('Commits and pull requests from this username will act in your name.')
                    ->action(function (IntegrationIdentity $record): void {
                        app(Identities::class)->claim($record, auth()->user());

                        Notification::make()->success()->title('@'.$record->provider_username.' is you.')->send();
                    }),
            ]);
    }
}
