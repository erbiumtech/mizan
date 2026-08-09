<?php

namespace App\Modules\Projects\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Models\ProjectEnvironment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Certificates expiring within the alert window. Hidden entirely when there is
 * nothing to renew, rather than showing a reassuring empty box.
 */
class CertificateExpiryTable extends TableWidget
{
    use WidgetBelongsToModule;

    protected static bool $isLazy = true;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return 'Certificates expiring soon';
    }

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        if (! auth()->user()?->can('ProjectView')) {
            return false;
        }

        return static::baseQuery()->exists();
    }

    protected static function baseQuery()
    {
        $window = max((array) config('projects.ssl.thresholds', [30]));

        return ProjectEnvironment::query()
            ->whereNotNull('ssl_expires_at')
            ->where('ssl_expires_at', '<=', now()->addDays((int) $window));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::baseQuery()->with('project'))
            ->columns([
                TextColumn::make('project.name')
                    ->label('Project')
                    ->url(fn (ProjectEnvironment $record): string => ProjectResource::getUrl('view', ['record' => $record->project_id])),

                TextColumn::make('kind')
                    ->label('Environment')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ProjectEnvironment::KINDS[$state] ?? $state),

                TextColumn::make('ssl_expires_at')->label('Expires')->dateTime(),

                TextColumn::make('days_left')
                    ->label('Days left')
                    ->badge()
                    ->state(fn (ProjectEnvironment $record): string => (string) max(0, (int) $record->sslDaysRemaining()))
                    ->color(fn (ProjectEnvironment $record): string => match (true) {
                        $record->sslDaysRemaining() <= 7 => 'danger',
                        $record->sslDaysRemaining() <= 30 => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('ssl_issuer')->label('Issuer')->placeholder('—'),
            ])
            ->defaultSort('ssl_expires_at')
            ->paginated([5, 10]);
    }
}
