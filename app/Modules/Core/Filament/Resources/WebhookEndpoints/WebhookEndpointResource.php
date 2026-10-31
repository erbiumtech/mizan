<?php

namespace App\Modules\Core\Filament\Resources\WebhookEndpoints;

use App\Filament\Support\RecordSheet;
use App\Modules\Core\Filament\Resources\WebhookEndpoints\Pages\CreateWebhookEndpoint;
use App\Modules\Core\Filament\Resources\WebhookEndpoints\Pages\EditWebhookEndpoint;
use App\Modules\Core\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Modules\Core\Jobs\DeliverWebhook;
use App\Modules\Core\Models\WebhookDelivery;
use App\Modules\Core\Models\WebhookEndpoint;
use App\Support\Webhooks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Where this company's own systems listen — docs/saas-launch-plan.md §14.9. */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Webhooks';

    protected static ?string $modelLabel = 'Webhook';

    protected static ?int $navigationSort = 88;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)->placeholder('Our HR system'),
            TextInput::make('url')
                ->label(__('URL'))
                ->required()
                ->url()
                ->maxLength(255)
                ->helperText('An HTTPS address that answers POST. Anything in the 200s counts as delivered.'),
            Toggle::make('is_active')->label(__('Active'))->default(true),
            CheckboxList::make('events')
                ->label(__('Send me'))
                ->options(fn (): array => Webhooks::options())
                ->columns(2)
                ->required()
                ->columnSpanFull()
                ->helperText('Every delivery carries an X-Mizan-Signature header: sha256= and an HMAC of the body '
                    .'under this endpoint\'s secret. Check it before you trust the body.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (WebhookEndpoint $e): string => (string) $e->url),
                TextColumn::make('events')
                    ->label(__('Listening for'))
                    ->state(fn (WebhookEndpoint $e): string => implode(', ', $e->eventLabels()) ?: 'nothing')
                    ->wrap(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('last_delivered_at')->label(__('Last delivered'))->dateTime('d M Y H:i')->placeholder('never'),
                TextColumn::make('consecutive_failures')
                    ->label(__('Failing'))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (int $state): string => $state.' in a row')
                    ->visible(fn (): bool => WebhookEndpoint::query()->where('consecutive_failures', '>', 0)->exists()),
            ])
            ->recordActions([
                Action::make('test')
                    ->label(__('Send a test'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('A delivery with a made-up body, signed the same way as a real one, so you '
                        .'can check the signature at your end before anything real depends on it.')
                    ->action(function (WebhookEndpoint $record): void {
                        $delivery = WebhookDelivery::create([
                            'webhook_endpoint_id' => $record->getKey(),
                            'event' => 'test',
                            'payload' => ['message' => 'This is a test delivery from '.config('app.name').'.', 'at' => now()->toIso8601String()],
                        ]);

                        DeliverWebhook::dispatch($delivery->getKey());

                        Notification::make()->success()->title('Queued. The delivery log says what came back.')->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEndpoints::route('/'),
            'create' => CreateWebhookEndpoint::route('/create'),
            'edit' => EditWebhookEndpoint::route('/{record}/edit'),
        ];
    }
}
