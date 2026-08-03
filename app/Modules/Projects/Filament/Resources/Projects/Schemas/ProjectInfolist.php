<?php

namespace App\Modules\Projects\Filament\Resources\Projects\Schemas;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectEnvironment;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Project')
                ->columns(3)
                ->schema([
                    TextEntry::make('code'),
                    TextEntry::make('name'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => Project::STATUSES[$state] ?? $state),
                    TextEntry::make('start_date')->date()->placeholder('—'),
                    TextEntry::make('end_date')->date()->placeholder('—'),
                    TextEntry::make('employees_count')
                        ->label('Team size')
                        ->state(fn (Project $record): int => $record->employees()->count()),
                    TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Responsibility')
                ->columns(2)
                ->description('The secondary manager stands in when the primary is unavailable.')
                ->schema([
                    TextEntry::make('manager.employee_id')
                        ->label('Primary manager')
                        ->formatStateUsing(fn ($state, Project $record): string => $record->manager?->display_label ?? '—')
                        ->placeholder('—'),
                    TextEntry::make('secondaryManager.employee_id')
                        ->label('Secondary manager')
                        ->formatStateUsing(fn ($state, Project $record): string => $record->secondaryManager?->display_label ?? '—')
                        ->placeholder('—'),
                ]),

            Section::make('Environments')
                ->schema([
                    RepeatableEntry::make('environments')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('kind')
                                ->label('Environment')
                                ->badge()
                                ->formatStateUsing(fn (string $state): string => ProjectEnvironment::KINDS[$state] ?? $state),

                            TextEntry::make('url')
                                ->label('URL')
                                ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                                ->copyable()
                                ->placeholder('—'),

                            TextEntry::make('health_status')
                                ->label('Health')
                                ->badge()
                                ->formatStateUsing(fn (?string $state): string => $state ?? 'unknown')
                                ->color(fn (?string $state): string => match ($state) {
                                    ProjectEnvironment::HEALTH_UP => 'success',
                                    ProjectEnvironment::HEALTH_DOWN => 'danger',
                                    default => 'gray',
                                })
                                ->helperText(fn (ProjectEnvironment $record): ?string => $record->health_checked_at
                                    ? 'checked '.$record->health_checked_at->diffForHumans()
                                    : 'never checked'),

                            TextEntry::make('username')->copyable()->placeholder('—'),

                            TextEntry::make('password')
                                ->label('Password')
                                ->copyable()
                                ->copyMessage('Password copied')
                                ->placeholder('—'),

                            TextEntry::make('uptime')
                                ->label('Uptime (30d)')
                                ->state(function (ProjectEnvironment $record): string {
                                    $uptime = $record->uptimePercent(config('projects.status_page.uptime_days', 30));

                                    return $uptime === null ? '—' : number_format($uptime, 2).'%';
                                }),

                            TextEntry::make('health_latency_ms')
                                ->label('Latency')
                                ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : $state.' ms')
                                ->placeholder('—'),

                            TextEntry::make('ssl_expires_at')
                                ->label('Certificate expires')
                                ->dateTime()
                                ->color(fn (ProjectEnvironment $record): string => match (true) {
                                    $record->sslDaysRemaining() === null => 'gray',
                                    $record->sslDaysRemaining() <= 7 => 'danger',
                                    $record->sslDaysRemaining() <= 30 => 'warning',
                                    default => 'gray',
                                })
                                ->placeholder('—'),

                            TextEntry::make('health_error')
                                ->label('Last error')
                                ->placeholder('—')
                                ->columnSpanFull(),

                            TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                        ]),
                ]),
        ]);
    }
}
