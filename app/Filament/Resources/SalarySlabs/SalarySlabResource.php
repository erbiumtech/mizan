<?php

namespace App\Filament\Resources\SalarySlabs;

use App\Filament\Resources\SalarySlabs\Pages\CreateSalarySlab;
use App\Filament\Resources\SalarySlabs\Pages\EditSalarySlab;
use App\Filament\Resources\SalarySlabs\Pages\ListSalarySlabs;
use App\Filament\Resources\SalarySlabs\Schemas\SalarySlabForm;
use App\Filament\Resources\SalarySlabs\Tables\SalarySlabsTable;
use App\Models\SalarySlab;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SalarySlabResource extends Resource
{
    protected static ?string $model = SalarySlab::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Salary Slab & Fiscal Year';

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
