<?php

namespace App\Modules\Employees\Filament\Resources\Departments;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Modules\Employees\Filament\Resources\Departments\Pages\EditDepartment;
use App\Modules\Employees\Filament\Resources\Departments\Pages\ListDepartments;
use App\Modules\Employees\Models\Department;
use App\Modules\Employees\Models\Employee;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Departments as rows — docs/saas-launch-plan.md §14.2. */
class DepartmentResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Department::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->unique(ignoreRecord: true)->helperText('Renaming follows the employees who hold it.'),
            TextInput::make('code')->maxLength(20),
            Select::make('parent_id')->label('Part of')->relationship('parent', 'name', fn ($query, ?Department $record) => $query->when($record, fn ($q) => $q->whereKeyNot($record->getKey())))->nullable(),
            Select::make('head_employee_id')->label('Head')
                ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()->nullable(),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('code')->placeholder('—'),
                TextColumn::make('parent.name')->label('Part of')->placeholder('—'),
                TextColumn::make('head.display_label')->label('Head')->placeholder('—'),
                TextColumn::make('employees_count')->counts('employees')->label('People'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepartments::route('/'),
            'create' => CreateDepartment::route('/create'),
            'edit' => EditDepartment::route('/{record}/edit'),
        ];
    }
}
