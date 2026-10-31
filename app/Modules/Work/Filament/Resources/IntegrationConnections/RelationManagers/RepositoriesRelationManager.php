<?php

namespace App\Modules\Work\Filament\Resources\IntegrationConnections\RelationManagers;

use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\WorkRepository;
use App\Modules\Work\Services\CodeHosts;
use App\Support\Integrations;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The repositories this connection has linked, and the one action that links another — §8.1.
 *
 * **Link repository** asks the provider what the token can see and offers the list, so a project
 * owner picks theirs rather than typing a name; the webhook is registered on the way, with a
 * secret this application generated.
 */
class RepositoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'repositories';

    protected static ?string $title = 'Repositories';

    /** Only a code host has repositories; a time tracker's tab is TimeProjectsRelationManager. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof IntegrationConnection
            && $ownerRecord->kind === Integrations::CODE
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                TextColumn::make('full_name')->label('Repository')->searchable()
                    ->url(fn (WorkRepository $record): ?string => $record->url, shouldOpenInNewTab: true),
                TextColumn::make('project.name')->label('Project'),
                TextColumn::make('default_branch')->label('Default branch'),
                TextColumn::make('webhook_id')
                    ->label('Hook')
                    ->badge()
                    ->state(fn (WorkRepository $record): string => match (true) {
                        ! $record->isLinked() => 'Unlinked',
                        filled($record->webhook_id) => 'Registered',
                        default => 'Missing',
                    })
                    ->color(fn (WorkRepository $record): string => match (true) {
                        ! $record->isLinked() => 'gray',
                        filled($record->webhook_id) => 'success',
                        default => 'danger',
                    }),
                TextColumn::make('linked_at')->label('Linked')->since(),
            ])
            ->headerActions([
                Action::make('link')
                    ->label('Link repository')
                    ->icon('heroicon-o-link')
                    ->visible(fn (): bool => auth()->user()?->can('create', WorkRepository::class) ?? false)
                    ->schema(fn (): array => [
                        Select::make('repository')
                            ->label('Repository')
                            ->options(function (): array {
                                /** @var IntegrationConnection $connection */
                                $connection = $this->getOwnerRecord();

                                try {
                                    return collect(app(CodeHosts::class)->available($connection))
                                        ->mapWithKeys(fn (array $repo): array => [$repo['id'] => $repo['full_name']])
                                        ->all();
                                } catch (RuntimeException $e) {
                                    Notification::make()->danger()->title('Could not list repositories')->body($e->getMessage())->send();

                                    return [];
                                }
                            })
                            ->searchable()
                            ->required(),

                        Select::make('project_id')
                            ->label('Project')
                            ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var IntegrationConnection $connection */
                        $connection = $this->getOwnerRecord();

                        try {
                            $repo = collect(app(CodeHosts::class)->available($connection))->firstWhere('id', (string) $data['repository']);

                            if ($repo === null) {
                                throw new RuntimeException('That repository is no longer visible to this connection.');
                            }

                            $linked = app(CodeHosts::class)->link($connection, (int) $data['project_id'], $repo);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not linked')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title($linked->full_name.' is linked.')->body('The webhook is registered on GitHub.')->send();
                    }),
            ])
            ->recordActions([
                Action::make('rehook')
                    ->label('Register hook')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (WorkRepository $record): bool => $record->isLinked() && blank($record->webhook_id)
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (WorkRepository $record): void {
                        try {
                            $hosts = app(CodeHosts::class);
                            $id = $hosts->provider($record->connection)->registerWebhook($record, $hosts->hookUrl($record->connection), $record->connection->webhook_secret);
                            $record->forceFill(['webhook_id' => $id])->save();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Still no hook')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Hook registered.')->send();
                    }),

                Action::make('unlink')
                    ->label('Unlink')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The hook is removed from the provider and the repository stops delivering. Commits, branches and pull requests already attached to items stay.')
                    ->visible(fn (WorkRepository $record): bool => $record->isLinked() && (auth()->user()?->can('delete', $record) ?? false))
                    ->action(fn (WorkRepository $record) => app(CodeHosts::class)->unlink($record)),
            ]);
    }
}
