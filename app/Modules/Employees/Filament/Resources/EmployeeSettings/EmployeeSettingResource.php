<?php

namespace App\Modules\Employees\Filament\Resources\EmployeeSettings;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages\CreateEmployeeSetting;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages\EditEmployeeSetting;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages\ListEmployeeSettings;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Schemas\EmployeeSettingForm;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\Tables\EmployeeSettingsTable;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Support\ResourceContributions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmployeeSettingResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = EmployeeSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'id',
            'employee.employee_id',
            'employee.user.name',
            'fiscalYear.name',
        ];
    }

    /**
     * Privileged roles see all settings; everyone else sees their own plus
     * those of their reporting downline.
     */
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
        return EmployeeSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeSettingsTable::configure($table);
    }

    /**
     * A slot, not a list.
     *
     * The added-components tab is Payroll's — it edits `EmployeeSettingComponent` and offers
     * `PayComponent` — and it used to live here, naming both by their full class names to keep the import
     * out of the lint. Payroll requires Employees, so that pair was a cycle whichever way it was written.
     * Payroll now registers the tab from its own provider; see `App\Support\ResourceContributions` and
     * docs/module-packaging-plan.md §11.
     */
    public static function getRelations(): array
    {
        return ResourceContributions::relationManagersFor(static::class);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeSettings::route('/'),
            'create' => CreateEmployeeSetting::route('/create'),
            'edit' => EditEmployeeSetting::route('/{record}/edit'),
        ];
    }
}
