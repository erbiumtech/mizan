<?php

namespace App\Modules\Attendance\Filament\Resources\RestDaySwaps;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Attendance\Filament\Resources\RestDaySwaps\Pages\CreateRestDaySwap;
use App\Modules\Attendance\Filament\Resources\RestDaySwaps\Pages\ListRestDaySwaps;
use App\Modules\Attendance\Models\RestDaySwap;
use App\Modules\Employees\Models\Employee;
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

    protected static ?string $model = RestDaySwap::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Rest-day Swaps';

    protected static ?string $modelLabel = 'Rest-day swap';

    protected static ?int $navigationSort = 43;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('employee.user');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')->label('Employee')
                ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()->required(),
            DatePicker::make('work_on')->label('Works on (a rest day)')->native(false)->required(),
            DatePicker::make('rest_on')->label('Rests on (a working day)')->native(false)->required()->different('work_on'),
            TextInput::make('reason')->maxLength(190),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')->label('Employee'),
                TextColumn::make('work_on')->date('d M Y')->label('Works on')->sortable(),
                TextColumn::make('rest_on')->date('d M Y')->label('Rests on'),
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
