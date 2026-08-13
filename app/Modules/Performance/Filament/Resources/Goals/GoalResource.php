<?php

namespace App\Modules\Performance\Filament\Resources\Goals;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Filament\Resources\Goals\Pages\CreateGoal;
use App\Modules\Performance\Filament\Resources\Goals\Pages\EditGoal;
use App\Modules\Performance\Filament\Resources\Goals\Pages\ListGoals;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\ReviewCycle;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class GoalResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = Goal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 12;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->options(fn (): array => app(EmployeeAccess::class)
                    ->scopeAccessibleEmployees(Employee::query()->where('is_active', true), auth()->user())
                    ->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                    ->all())
                ->searchable()
                ->required()
                ->default(fn (): ?int => Employee::where('user_id', auth()->id())->value('id')),

            Select::make('review_cycle_id')
                ->label('Cycle')
                ->options(fn (): array => ReviewCycle::orderByDesc('period_start')->pluck('name', 'id')->all())
                ->helperText('Optional: a goal may outlive the cycle it was set in, or sit outside one entirely.'),

            TextInput::make('title')->required()->maxLength(255),

            Select::make('status')
                ->options([
                    Goal::STATUS_OPEN => 'Open',
                    Goal::STATUS_ACHIEVED => 'Achieved',
                    Goal::STATUS_MISSED => 'Missed',
                    Goal::STATUS_DROPPED => 'Dropped',
                ])
                ->default(Goal::STATUS_OPEN)
                ->required(),

            TextInput::make('metric')->maxLength(255)->placeholder('Tickets closed per month')
                ->helperText('How it is measured, in words. Nothing computes this.'),

            TextInput::make('target')->maxLength(255),
            TextInput::make('actual')->maxLength(255),

            TextInput::make('weight')->numeric()->suffix('%')
                ->helperText('Where a company weights goals against each other. Optional.'),

            DatePicker::make('due_on')->native(false),

            Textarea::make('description')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('title')->searchable()->wrap(),

                TextColumn::make('target')
                    ->placeholder('—')
                    ->description(fn (Goal $record): ?string => $record->actual ? 'actual: '.$record->actual : null)
                    ->toggleable(),

                TextColumn::make('due_on')->label('Due')->date('d M Y')->placeholder('—')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Goal::STATUS_ACHIEVED => 'success',
                        Goal::STATUS_MISSED => 'danger',
                        Goal::STATUS_DROPPED => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
            ])
            ->defaultSort('due_on')
            ->filters([
                SelectFilter::make('status')->options([
                    Goal::STATUS_OPEN => 'Open',
                    Goal::STATUS_ACHIEVED => 'Achieved',
                    Goal::STATUS_MISSED => 'Missed',
                    Goal::STATUS_DROPPED => 'Dropped',
                ]),
            ])
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoals::route('/'),
            'create' => CreateGoal::route('/create'),
            'edit' => EditGoal::route('/{record}/edit'),
        ];
    }
}
