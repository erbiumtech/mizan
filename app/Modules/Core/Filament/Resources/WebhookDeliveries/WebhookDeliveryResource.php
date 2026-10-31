<?php

namespace App\Modules\Core\Filament\Resources\WebhookDeliveries;

use App\Modules\Core\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Modules\Core\Jobs\DeliverWebhook;
use App\Modules\Core\Models\WebhookDelivery;
use App\Support\Webhooks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * What went where, and what came back — docs/saas-launch-plan.md §14.9.
 *
 * The log is the feature rather than a byproduct. A webhook that fails quietly is
 * worse than one nobody configured: the system at the other end goes on believing it
 * is in step. Every attempt is here, the failures included.
 */
class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Audit & Taxes';

    protected static ?string $navigationLabel = 'Webhook Deliveries';

    protected static ?string $modelLabel = 'Delivery';

    protected static ?int $navigationSort = 45;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('endpoint');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('Raised'))->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('endpoint.name')->label(__('Endpoint'))->placeholder('deleted'),
                TextColumn::make('event')
                    ->formatStateUsing(fn (string $state): string => Webhooks::options()[$state] ?? $state),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WebhookDelivery::STATUS_DELIVERED => 'success',
                        WebhookDelivery::STATUS_FAILED => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (WebhookDelivery $record): ?string => $record->response_code
                        ? 'answered '.$record->response_code.' after '.$record->attempts.' attempt(s)'
                        : null),
                TextColumn::make('error')->limit(60)->placeholder('—')->wrap()->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('event')->options(fn (): array => Webhooks::options()),
                Filter::make('failed')
                    ->label(__('Failed only'))
                    ->query(fn (Builder $query): Builder => $query->failed()),
            ])
            ->recordActions([
                Action::make('body')
                    ->label(__('Body'))
                    ->icon('heroicon-o-code-bracket')
                    ->color('gray')
                    ->modalHeading('What was sent')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (WebhookDelivery $record): HtmlString => new HtmlString(
                        '<pre class="text-xs overflow-x-auto">'.e((string) json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>'
                    )),
                Action::make('resend')
                    ->label(__('Send again'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('The same body to the same endpoint, signed again. Use it after fixing '
                        .'whatever was wrong at the other end.')
                    ->visible(fn (WebhookDelivery $record): bool => ! $record->isDelivered() && $record->endpoint !== null)
                    ->action(function (WebhookDelivery $record): void {
                        $record->update(['status' => WebhookDelivery::STATUS_PENDING, 'attempts' => 0, 'error' => null]);
                        DeliverWebhook::dispatch($record->getKey());

                        Notification::make()->success()->title('Queued again.')->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWebhookDeliveries::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
