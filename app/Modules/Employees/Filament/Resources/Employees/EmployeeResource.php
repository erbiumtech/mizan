<?php

namespace App\Modules\Employees\Filament\Resources\Employees;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Employees\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Modules\Employees\Filament\Resources\Employees\Pages\EditEmployee;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ListEmployees;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Filament\Resources\Employees\RelationManagers\ChangeRequestsRelationManager;
use App\Modules\Employees\Filament\Resources\Employees\RelationManagers\ProjectsRelationManager;
use App\Modules\Employees\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Modules\Employees\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Modules\Employees\Models\Employee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmployeeResource extends Resource
{
    use BelongsToModule;

    use ScopesToAccessibleEmployees;

    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'employee_id';

    public static function getGloballySearchableAttributes(): array
    {
        // Parity with Nova searchableColumns(): employee_id + related user name.
        return ['employee_id', 'user.name'];
    }

    /**
     * Privileged roles see all; everyone else sees their own record plus their
     * reporting downline (managers see their whole subtree).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('customFieldValues.customField');

        if (! static::userIsPrivileged()) {
            $query->whereIn('id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChangeRequestsRelationManager::class,
            ProjectsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'view' => ViewEmployee::route('/{record}'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
