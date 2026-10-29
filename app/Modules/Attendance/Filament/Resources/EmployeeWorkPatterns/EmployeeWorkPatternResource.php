<?php

namespace App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\Pages\CreateEmployeeWorkPattern;
use App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\Pages\EditEmployeeWorkPattern;
use App\Modules\Attendance\Filament\Resources\EmployeeWorkPatterns\Pages\ListEmployeeWorkPatterns;
use App\Modules\Attendance\Models\EmployeeWorkPattern;
use App\Modules\Attendance\Models\WorkPattern;
use App\Modules\Employees\Models\Employee;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Who works which pattern, from when — docs/saas-launch-plan.md §14.4, over the dated table the resolver reads. */
class EmployeeWorkPatternResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = EmployeeWorkPattern::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Pattern Assignments';

    protected static ?string $modelLabel = 'Pattern assignment';

    protected static ?int $navigationSort = 44;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.user', 'workPattern']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')->label('Employee')
                ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()->required(),
            Select::make('work_pattern_id')->label('Pattern')->options(fn (): array => WorkPattern::query()->orderBy('name')->pluck('name', 'id')->all())->required(),
            DatePicker::make('from_date')->label('From')->native(false)->required(),
            DatePicker::make('to_date')->label('To')->native(false)->nullable()->afterOrEqual('from_date')->helperText('Leave blank while it still applies.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')->label('Employee'),
                TextColumn::make('workPattern.name')->label('Pattern'),
                TextColumn::make('from_date')->date('d M Y')->sortable(),
                TextColumn::make('to_date')->date('d M Y')->placeholder('ongoing'),
            ])
            ->filters([SelectFilter::make('work_pattern_id')->label('Pattern')->relationship('workPattern', 'name')])
            ->defaultSort('from_date', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeWorkPatterns::route('/'),
            'create' => CreateEmployeeWorkPattern::route('/create'),
            'edit' => EditEmployeeWorkPattern::route('/{record}/edit'),
        ];
    }
}
