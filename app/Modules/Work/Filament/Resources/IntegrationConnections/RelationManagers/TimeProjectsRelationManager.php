<?php

namespace App\Modules\Work\Filament\Resources\IntegrationConnections\RelationManagers;

use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\WorkTimeProject;
use App\Modules\Work\Services\IntegrationEvents;
use App\Modules\Work\Services\WorkItems;
use App\Support\Integrations;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A tracker's projects, linked to ours — docs/project-management-plan.md §8.8.
 *
 * The link is made by picking from the workspace's project list, or by **creating** the tracker's
 * project from here with the same name and the key as its code — the plan's two ways in. The
 * tab is the project page's "Connections", read from the connection's side.
 */
class TimeProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'timeProjects';

    protected static ?string $title = 'Time projects';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof IntegrationConnection
            && $ownerRecord->kind === Integrations::TIME
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label(__('In the tracker'))->searchable(),
                TextColumn::make('external_project_id')->label(__('Tracker id'))->copyable(),
                TextColumn::make('project.name')->label(__('Project here')),
                TextColumn::make('tasks_count')->counts('tasks')->label(__('Tasks'))->alignEnd(),
            ])
            ->headerActions([
                Action::make('link')
                    ->label(__('Link project'))
                    ->icon('heroicon-o-link')
                    ->visible(fn (): bool => auth()->user()?->can('create', WorkTimeProject::class) ?? false)
                    ->schema(fn (): array => [
                        Select::make('project_id')
                            ->label(__('Project here'))
                            ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),

                        Radio::make('how')
                            ->label(__('In the tracker'))
                            ->options(['pick' => 'Pick an existing project', 'create' => 'Create one with the same name and the key as its code'])
                            ->default('pick')
                            ->live()
                            ->required(),

                        Select::make('external')
                            ->label(__('Tracker project'))
                            ->options(function (): array {
                                /** @var IntegrationConnection $connection */
                                $connection = $this->getOwnerRecord();

                                try {
                                    return collect(app(IntegrationEvents::class)->timeProviderFor($connection)->projects($connection))
                                        ->mapWithKeys(fn (array $project): array => [$project['id'] => $project['name']])
                                        ->all();
                                } catch (RuntimeException $e) {
                                    Notification::make()->danger()->title('Could not list projects')->body($e->getMessage())->send();

                                    return [];
                                }
                            })
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('how') === 'pick')
                            ->required(fn (Get $get): bool => $get('how') === 'pick'),
                    ])
                    ->action(function (array $data): void {
                        /** @var IntegrationConnection $connection */
                        $connection = $this->getOwnerRecord();
                        $project = Project::query()->findOrFail($data['project_id']);

                        try {
                            $provider = app(IntegrationEvents::class)->timeProviderFor($connection);

                            if (($data['how'] ?? 'pick') === 'create') {
                                $made = $provider->createProject($connection, $project->name, app(WorkItems::class)->key($project));
                            } else {
                                $made = collect($provider->projects($connection))->firstWhere('id', (string) $data['external'])
                                    ?? throw new RuntimeException('That project is no longer in the workspace.');
                            }
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not linked')->body($e->getMessage())->send();

                            return;
                        }

                        WorkTimeProject::query()->updateOrCreate(
                            ['integration_connection_id' => $connection->getKey(), 'external_project_id' => (string) $made['id']],
                            ['project_id' => $project->getKey(), 'name' => (string) $made['name']],
                        );

                        Notification::make()->success()->title($made['name'].' is linked to '.$project->name.'.')->send();
                    }),
            ])
            ->recordActions([
                DeleteAction::make()->label(__('Unlink'))
                    ->modalDescription('Entries already in the timesheet stay. New entries on this tracker project will be held until it is linked again.'),
            ]);
    }
}
