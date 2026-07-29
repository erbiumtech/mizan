<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The signed-in user's own projects — managed or assigned — so the dashboard is
 * useful to an individual and not only to whoever owns everything. Renders
 * nothing for a user with no employee record (e.g. a bare administrator).
 */
class MyProjectsOverview extends TableWidget
{
    use WidgetBelongsToModule;

    protected static bool $isLazy = true;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return 'My projects';
    }

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return (bool) auth()->user()?->can('ProjectView') && Employee::forUser() !== null;
    }

    public function table(Table $table): Table
    {
        $employee = Employee::forUser();

        return $table
            ->query(function () use ($employee): Builder {
                $query = Project::query()->with('environments');

                if (! $employee) {
                    // whereRaw(0=1): an empty result set, not every project.
                    return $query->whereRaw('1 = 0');
                }

                return $query->where(function (Builder $query) use ($employee) {
                    $query->where('manager_employee_id', $employee->getKey())
                        ->orWhere('secondary_employee_id', $employee->getKey())
                        ->orWhereHas('employees', fn (Builder $q) => $q->whereKey($employee->getKey()));
                });
            })
            ->columns([
                TextColumn::make('code')
                    ->url(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record])),

                TextColumn::make('name')->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Project::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Project::STATUS_ACTIVE => 'success',
                        Project::STATUS_PLANNED => 'info',
                        Project::STATUS_ON_HOLD => 'warning',
                        Project::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('my_role')
                    ->label('My role')
                    ->state(function (Project $record) use ($employee): string {
                        $roles = [];

                        if ($employee && (int) $record->manager_employee_id === (int) $employee->getKey()) {
                            $roles[] = 'Primary manager';
                        }

                        if ($employee && (int) $record->secondary_employee_id === (int) $employee->getKey()) {
                            $roles[] = 'Secondary manager';
                        }

                        if ($roles === []) {
                            $roles[] = 'Team member';
                        }

                        return implode(' · ', $roles);
                    }),

                TextColumn::make('health')
                    ->label('Environments')
                    ->badge()
                    ->state(fn (Project $record): string => $record->worstEnvironmentStatus())
                    ->color(fn (string $state): string => match ($state) {
                        ProjectEnvironment::HEALTH_UP => 'success',
                        ProjectEnvironment::HEALTH_DOWN => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('code')
            ->emptyStateHeading('You are not on any project yet')
            ->paginated([5, 10, 25]);
    }
}
