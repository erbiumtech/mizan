<?php

namespace App\Modules\Employees\Filament\Resources\Employees;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Employees\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Modules\Employees\Filament\Resources\Employees\Pages\EditEmployee;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ListEmployees;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Filament\Resources\Employees\RelationManagers\ChangeRequestsRelationManager;
use App\Modules\Employees\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Modules\Employees\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Modules\Employees\Models\Employee;
use App\Support\ResourceContributions;
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

    /**
     * This resource's own tabs, plus whatever other modules have contributed.
     *
     * The Projects tab used to be named here, and that made Employees depend on Projects — while Projects
     * already requires Employees, so the pair was a cycle and neither could become a package. Projects now
     * registers its own tab from its service provider (see App\Support\ResourceContributions), which
     * means it is present exactly when Projects is, and this file no longer knows Projects exists.
     */
    public static function getRelations(): array
    {
        return [
            ChangeRequestsRelationManager::class,
            ...ResourceContributions::relationManagersFor(static::class),
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
