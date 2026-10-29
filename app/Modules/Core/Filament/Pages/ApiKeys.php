<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\ApiAbilities;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;
use UnitEnum;

/**
 * API keys, over Sanctum's tokens — docs/saas-launch-plan.md §14.9.
 *
 * **Your own keys, never the company's.** A token is issued to a user and carries
 * that user's permissions wherever it goes, so a screen that let an administrator
 * create a key "for the company" would be creating a key that acts as them, with no
 * trace of who asked for it. Each person makes their own, and revoking somebody's
 * access revokes their keys with it.
 *
 * The key is shown once. There is nowhere to look it up afterwards because the table
 * holds a hash, which is the point — this page cannot leak what it does not have.
 *
 * `api` is forced onto every key: `RequireTokenAbility` rejects a request whose token
 * lacks it, so a key without it would be a key that cannot be used for anything.
 */
class ApiKeys extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.simple-table';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'API Keys';

    protected static ?int $navigationSort = 89;

    /** How long a key may be good for, and what the screen calls each. */
    private const LIFETIMES = [
        '30' => '30 days',
        '90' => '90 days',
        '365' => 'A year',
        '0' => 'Until it is revoked',
    ];

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PersonalAccessToken::query()
                ->where('tokenable_type', auth()->user()?->getMorphClass())
                ->where('tokenable_id', auth()->id()))
            ->columns([
                TextColumn::make('name')->label('What it is for')->searchable(),
                TextColumn::make('abilities')
                    ->label('Scopes')
                    ->state(fn (PersonalAccessToken $record): string => collect((array) $record->abilities)
                        ->map(fn (string $ability): string => ApiAbilities::all()[$ability] ?? $ability)
                        ->implode(', ')),
                TextColumn::make('created_at')->label('Made')->dateTime('d M Y')->sortable(),
                // The column people actually come here for: a key nobody has used in a year
                // is a key nobody will miss, and a key used this morning is one to ask about
                // before revoking.
                TextColumn::make('last_used_at')->label('Last used')->dateTime('d M Y H:i')->placeholder('never')->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('d M Y')
                    ->placeholder('never')
                    ->color(fn (?string $state): string => $state !== null && now()->gt($state) ? 'danger' : 'gray')
                    ->description(fn (PersonalAccessToken $record): ?string => $record->expires_at && now()->gt($record->expires_at) ? 'expired' : null),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Anything using this key stops working immediately. It cannot be undone — '
                        .'make a new key instead.')
                    ->action(function (PersonalAccessToken $record): void {
                        $record->delete();
                        Notification::make()->success()->title('Revoked.')->send();
                    }),
            ])
            ->emptyStateHeading('No keys yet')
            ->emptyStateDescription('A key lets a script or another system act as you through the API.');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('api-keys', 'API Keys: Help'),
            Action::make('newKey')
                ->label('New key')
                ->icon('heroicon-o-plus')
                ->modalHeading('Make an API key')
                ->modalSubmitActionLabel('Make the key')
                ->schema([
                    TextInput::make('name')
                        ->label('What it is for')
                        ->required()
                        ->maxLength(120)
                        ->placeholder('Attendance sync on the office server')
                        ->helperText('Name it after the thing that will use it, not after yourself. In a year this '
                            .'is all anybody will have to decide whether it is still needed.'),
                    CheckboxList::make('abilities')
                        ->label('Scopes')
                        ->options(ApiAbilities::all())
                        ->default(['api'])
                        ->helperText('Every key can use the API; there is nothing narrower to give yet.'),
                    Select::make('days')
                        ->label('Good for')
                        ->options(self::LIFETIMES)
                        ->default('90')
                        ->required()
                        ->helperText('A key that never expires is a key nobody ever revokes.'),
                ])
                ->action(function (array $data): void {
                    $days = (int) ($data['days'] ?? 0);

                    $token = auth()->user()->createToken(
                        $data['name'],
                        ApiAbilities::sanitise((array) ($data['abilities'] ?? [])),
                        $days > 0 ? now()->addDays($days) : null,
                    );

                    Notification::make()
                        ->success()
                        ->persistent()
                        ->title('Your key — copy it now')
                        ->body($token->plainTextToken."\n\nIt is not shown again. Send it as: Authorization: Bearer <key>")
                        ->send();
                }),
        ];
    }
}
