<?php

namespace App\Modules\Payroll\Filament\Resources\SalarySlabs;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Payroll\Filament\Resources\SalarySlabs\Pages\CreateSalarySlab;
use App\Modules\Payroll\Filament\Resources\SalarySlabs\Pages\EditSalarySlab;
use App\Modules\Payroll\Filament\Resources\SalarySlabs\Pages\ListSalarySlabs;
use App\Modules\Payroll\Filament\Resources\SalarySlabs\Schemas\SalarySlabForm;
use App\Modules\Payroll\Filament\Resources\SalarySlabs\Tables\SalarySlabsTable;
use App\Modules\Payroll\Models\SalarySlab;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SalarySlabResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = SalarySlab::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'fiscalYear.name'];
    }

    public static function form(Schema $schema): Schema
    {
        return SalarySlabForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalarySlabsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalarySlabs::route('/'),
            'create' => CreateSalarySlab::route('/create'),
            'edit' => EditSalarySlab::route('/{record}/edit'),
        ];
    }
}
