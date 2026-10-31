<?php

namespace App\Modules\Attendance\Filament\Resources\RestDaySwaps;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Filament\Support\RecordSheet;
use App\Modules\Attendance\Filament\Resources\RestDaySwaps\Pages\CreateRestDaySwap;
use App\Modules\Attendance\Filament\Resources\RestDaySwaps\Pages\ListRestDaySwaps;
use App\Modules\Attendance\Models\RestDaySwap;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Rest-day swaps — docs/saas-launch-plan.md §14.4. Resolved by WorkPatternResolver. */
class RestDaySwapResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = RestDaySwap::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Rest-day Swaps';

    protected static ?string $modelLabel = 'Rest-day swap';

    protected static ?int $navigationSort = 43;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('employee.user');

        // Own and downline unless privileged, like every employee-keyed list.
        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')->label(__('Employee'))
                ->options(fn (): array => app(EmployeeAccess::class)->scopeAccessibleEmployees(Employee::query()->where('is_active', true), auth()->user())->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()->required(),
            DatePicker::make('work_on')->label(__('Works on (a rest day)'))->native(false)->required(),
            DatePicker::make('rest_on')->label(__('Rests on (a working day)'))->native(false)->required()->different('work_on'),
            TextInput::make('reason')->maxLength(190),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')->label(__('Employee')),
                TextColumn::make('work_on')->date('d M Y')->label(__('Works on'))->sortable(),
                TextColumn::make('rest_on')->date('d M Y')->label(__('Rests on')),
                TextColumn::make('reason')->placeholder('—'),
            ])
            ->defaultSort('work_on', 'desc')
            ->recordActions([DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestDaySwaps::route('/'),
            'create' => CreateRestDaySwap::route('/create'),
        ];
    }
}
