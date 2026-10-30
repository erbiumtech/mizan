<?php

namespace App\Modules\Work\Filament\Resources\IntegrationConnections;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Work\Filament\Resources\IntegrationConnections\Pages\ListIntegrationConnections;
use App\Modules\Work\Filament\Resources\IntegrationConnections\Pages\ViewIntegrationConnection;
use App\Modules\Work\Filament\Resources\IntegrationConnections\RelationManagers\EventsRelationManager;
use App\Modules\Work\Filament\Resources\IntegrationConnections\RelationManagers\IdentitiesRelationManager;
use App\Modules\Work\Filament\Resources\IntegrationConnections\RelationManagers\RepositoriesRelationManager;
use App\Modules\Work\Models\IntegrationConnection;
use App\Support\Integrations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Integrations — docs/project-management-plan.md §8.1.
 *
 * A connection is a company's door to one provider account. Under *Settings*, because it holds a
 * credential to somebody else's system and §13.1 makes that an administrator's. What hangs off a
 * connection — its repositories, the identities it has seen, the deliveries it has received — are
 * the three tabs of its page.
 */
class IntegrationConnectionResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = IntegrationConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 62;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel = 'Integration';

    public static function getNavigationLabel(): string
    {
        return __('Integrations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider')
                ->label('Provider')
                ->options(fn (): array => Integrations::options(Integrations::CODE))
                ->required()
                ->native(false)
                ->live()
                ->disabledOn('edit'),

            TextInput::make('label')
                ->required()
                ->maxLength(120)
                ->placeholder('GitHub — erbiumtech')
                ->helperText('What this connection is called here. Two GitHub organisations are two connections.'),

            /*
             * How it authenticates — §8.2.
             *
             * A GitHub App installation is the way in for an organisation; the personal access token
             * is for the company whose policy forbids installing apps. The credential is encrypted and
             * never shown again.
             */
            Radio::make('auth_kind')
                ->label('Authenticates as')
                ->options([
                    IntegrationConnection::AUTH_APP => 'A GitHub App installation (recommended)',
                    IntegrationConnection::AUTH_TOKEN => 'A personal access token',
                ])
                ->default(IntegrationConnection::AUTH_APP)
                ->required()
                ->live()
                ->disabledOn('edit'),

            TextInput::make('credentials.installation_id')
                ->label('Installation id')
                ->numeric()
                ->visible(fn (Get $get): bool => $get('auth_kind') === IntegrationConnection::AUTH_APP)
                ->required(fn (Get $get): bool => $get('auth_kind') === IntegrationConnection::AUTH_APP)
                ->helperText(fn (): string => filled(config('work.github.app_slug'))
                    ? 'Install the App at https://github.com/apps/'.config('work.github.app_slug').' and copy the installation id from the URL you land on.'
                    : 'The server has no GitHub App configured yet: set WORK_GITHUB_APP_ID and WORK_GITHUB_PRIVATE_KEY, or use a personal access token.'),

            TextInput::make('credentials.token')
                ->label('Access token')
                ->password()
                ->revealable()
                ->visible(fn (Get $get): bool => $get('auth_kind') === IntegrationConnection::AUTH_TOKEN)
                ->required(fn (Get $get, ?IntegrationConnection $record): bool => $get('auth_kind') === IntegrationConnection::AUTH_TOKEN && $record === null)
                ->dehydrated(fn ($state): bool => filled($state))
                // Never shown back: the field opens blank on edit, and an empty save keeps the stored one.
                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                ->helperText('Needs repo and admin:repo_hook. Stored encrypted and never shown again; leave empty to keep the current one.'),

            TextInput::make('base_url')
                ->label('API base URL')
                ->url()
                ->placeholder('https://api.github.com')
                ->helperText('Only for a self-hosted instance. Leave empty for github.com.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['repositories', 'identities as unmatched_count' => fn (Builder $q) => $q->whereNull('user_id')]))
            ->columns([
                TextColumn::make('label')->searchable()->sortable()
                    ->description(fn (IntegrationConnection $record): string => $record->providerLabel().' · '.(IntegrationConnection::AUTH_KINDS[$record->auth_kind] ?? $record->auth_kind)),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, IntegrationConnection $record): string => match (true) {
                        $state === IntegrationConnection::PAUSED && ! $record->isOpen() => 'Paused until '.$record->paused_until?->format('H:i'),
                        $state === IntegrationConnection::PAUSED => 'Paused — will retry',
                        $state === IntegrationConnection::DISCONNECTED => 'Disconnected',
                        default => 'Active',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        IntegrationConnection::PAUSED => 'warning',
                        IntegrationConnection::DISCONNECTED => 'gray',
                        default => 'success',
                    }),

                TextColumn::make('repositories_count')->label('Repositories')->alignEnd(),

                TextColumn::make('unmatched_count')
                    ->label('Unmatched identities')
                    ->alignEnd()
                    ->color(fn (int $state): ?string => $state > 0 ? 'warning' : null),

                TextColumn::make('last_event_at')->label('Last delivery')->since()->placeholder('never'),

                TextColumn::make('last_failure')->label('Last failure')->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('label')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                // Manual resume, for the administrator who has fixed the token and does not want to
                // wait out the ten minutes.
                Action::make('resume')
                    ->label('Resume')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (IntegrationConnection $record): bool => $record->status !== IntegrationConnection::ACTIVE
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (IntegrationConnection $record): void {
                        $record->forceFill(['status' => IntegrationConnection::ACTIVE, 'paused_until' => null, 'consecutive_failures' => 0])->save();
                        Notification::make()->success()->title($record->label.' is active again.')->send();
                    }),

                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RepositoriesRelationManager::class,
            IdentitiesRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrationConnections::route('/'),
            'view' => ViewIntegrationConnection::route('/{record}'),
        ];
    }
}
