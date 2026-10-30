<?php

namespace App\Modules\Crm\Filament\Resources\FieldVisitStaff;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Crm\Filament\Resources\FieldVisitStaff\Pages\ListFieldVisitStaff;
use App\Modules\Crm\Models\FieldVisitStaff;
use App\Modules\Employees\Models\Employee;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Who does field visits — docs/saas-launch-plan.md §14.10.
 *
 * A short list in most companies: eighty employees and six on the road. Enrolling
 * somebody does nothing until *Only enrolled staff may be sent on visits* is switched
 * on under Company Settings, which is stated on the screen — a list that silently did
 * nothing would be worse than no list.
 */
class FieldVisitStaffResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = FieldVisitStaff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Field Visit Staff';

    protected static ?string $modelLabel = 'field visit rep';

    protected static ?int $navigationSort = 79;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')
                    ->orderBy('employee_id')->get()
                    ->mapWithKeys(fn (Employee $employee): array => [$employee->getKey() => $employee->display_label])->all())
                ->searchable()
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText('Enrolling somebody has no effect until the setting under Company Settings → Field '
                    .'visits is switched on.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')->label('Employee')->searchable(),
                TextColumn::make('employee.department')->label('Department')->placeholder('—'),
                TextColumn::make('enrolled_at')->label('Enrolled')->dateTime('d M Y')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([DeleteAction::make()->label('Remove')])
            ->emptyStateHeading('Nobody enrolled')
            ->emptyStateDescription('Until somebody is, every active employee can be named on a visit.')
            ->headerActions([CreateAction::make()->label('Enrol somebody')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFieldVisitStaff::route('/')];
    }
}
