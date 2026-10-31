<?php

namespace App\Modules\Work\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Services\CodeHosts;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One connection: where it stands, and its three tabs — repositories, identities, deliveries.
 *
 * The page says two things the list cannot: the URL the provider delivers to (for the
 * administrator checking GitHub's side) and how many deliveries are waiting on an identity match,
 * which is §8.5's "the connection's page says how many events are waiting".
 */
class ViewIntegrationConnection extends ViewRecord
{
    protected static string $resource = IntegrationConnectionResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->label;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->record->providerLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            HelpAction::make('work-integrations', 'Integrations: Help'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Where it stands')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state, IntegrationConnection $record): string => $record->isOpen() ? 'Active' : ucfirst($state))
                        ->color(fn (IntegrationConnection $record): string => $record->isOpen() ? 'success' : 'warning'),

                    TextEntry::make('auth_kind')
                        ->label(__('Authenticates as'))
                        ->formatStateUsing(fn (string $state): string => IntegrationConnection::AUTH_KINDS[$state] ?? $state),

                    TextEntry::make('last_event_at')->label(__('Last delivery'))->since()->placeholder('never'),

                    TextEntry::make('consecutive_failures')
                        ->label(__('Failures in a row'))
                        ->helperText(fn (IntegrationConnection $record): ?string => $record->last_failure),

                    TextEntry::make('waiting')
                        ->label(__('Deliveries waiting'))
                        ->state(fn (IntegrationConnection $record): int => $record->eventsWaitingOnIdentity())
                        ->helperText('Received and not yet processed, or failed and waiting on a fix.'),

                    TextEntry::make('hook_url')
                        ->label(__('Delivers to'))
                        ->state(fn (IntegrationConnection $record): string => app(CodeHosts::class)->hookUrl($record))
                        ->copyable()
                        ->columnSpanFull()
                        ->helperText('Registered on each linked repository by this application. Shown so GitHub\'s side can be checked against it.'),
                ]),
        ]);
    }
}
