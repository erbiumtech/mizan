<?php

namespace App\Filament\Resources\EmployeeSettings;

use App\Filament\Resources\EmployeeSettings\Pages\CreateEmployeeSetting;
use App\Filament\Resources\EmployeeSettings\Pages\EditEmployeeSetting;
use App\Filament\Resources\EmployeeSettings\Pages\ListEmployeeSettings;
use App\Filament\Resources\EmployeeSettings\Schemas\EmployeeSettingForm;
use App\Filament\Resources\EmployeeSettings\Tables\EmployeeSettingsTable;
use App\Models\EmployeeSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class EmployeeSettingResource extends Resource
{
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

    public static function form(Schema $schema): Schema
    {
        return EmployeeSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeSettingsTable::configure($table);
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
