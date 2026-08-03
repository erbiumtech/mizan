<?php

namespace App\Modules\Projects\Filament\Resources\Projects\Pages;

use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Filament\Resources\Projects\Schemas\ProjectInfolist;
use App\Modules\Projects\Filament\Resources\Projects\Widgets\ProjectHealthChart;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Services\EnvironmentHealthChecker;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function infolist(Schema $schema): Schema
    {
        return ProjectInfolist::configure($schema);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProjectHealthChart::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make($this->checkNowActions())
                ->label('Check now')
                ->icon('heroicon-m-signal')
                ->button()
                ->visible(fn (): bool => auth()->user()?->can('ProjectHealthCheck') ?? false),

            ActionGroup::make($this->muteActions())
                ->label('Mute alerts')
                ->icon('heroicon-m-bell-slash')
                ->button()
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('ProjectHealthCheck') ?? false),

            EditAction::make(),
        ];
    }

    /**
     * One synchronous check per environment. Running it inline (rather than
     * queueing) is the point of an on-demand check: the person clicking wants
     * the answer now, and a single request is cheap.
     *
     * @return array<Action>
     */
    protected function checkNowActions(): array
    {
        return $this->environmentActions(
            'check',
            fn (ProjectEnvironment $environment) => function (array $data = []) use ($environment): void {
                $result = app(EnvironmentHealthChecker::class)->check($environment);

                Notification::make()
                    ->title($environment->label().': '.($result->isUp ? 'up' : 'down'))
                    ->body(trim(($result->statusCode ? 'HTTP '.$result->statusCode.' · ' : '')
                        .($result->latencyMs !== null ? $result->latencyMs.'ms' : '')
                        .($result->error ? ' · '.$result->error : ''), ' ·'))
                    ->status($result->isUp ? 'success' : 'danger')
                    ->send();
            }
        );
    }

    /**
     * Mute suppresses alerts for a window while still recording checks, so a
     * planned deploy doesn't page anyone and doesn't leave a hole in uptime.
     *
     * @return array<Action>
     */
    protected function muteActions(): array
    {
        return $this->environmentActions(
            'mute',
            fn (ProjectEnvironment $environment) => function (array $data) use ($environment): void {
                $until = match ($data['duration']) {
                    'clear' => null,
                    '1h' => now()->addHour(),
                    '4h' => now()->addHours(4),
                    default => now()->addDay()->startOfDay(),
                };

                $environment->update(['muted_until' => $until]);

                Notification::make()
                    ->success()
                    ->title($until
                        ? $environment->label().' alerts muted until '.$until->format('j M H:i')
                        : $environment->label().' alerts un-muted')
                    ->send();
            },
            schema: [
                Select::make('duration')
                    ->label('Mute for')
                    ->options([
                        '1h' => '1 hour',
                        '4h' => '4 hours',
                        'tomorrow' => 'Until tomorrow',
                        'clear' => 'Un-mute now',
                    ])
                    ->default('1h')
                    ->required()
                    ->native(false),
            ]
        );
    }

    /**
     * Builds one action per environment of this project, keyed by kind.
     *
     * @return array<Action>
     */
    protected function environmentActions(string $prefix, callable $handler, array $schema = []): array
    {
        return collect(ProjectEnvironment::KINDS)
            ->map(function (string $label, string $kind) use ($prefix, $handler, $schema): Action {
                $action = Action::make("{$prefix}_{$kind}")
                    ->label($label)
                    ->visible(fn (Project $record): bool => $record->environments->firstWhere('kind', $kind)?->isMonitorable() ?? false)
                    ->action(function (Project $record, array $data = []) use ($kind, $handler): void {
                        $environment = $record->environments->firstWhere('kind', $kind);

                        if (! $environment) {
                            return;
                        }

                        $handler($environment)($data);
                    });

                return $schema ? $action->schema($schema) : $action;
            })
            ->values()
            ->all();
    }
}
