<?php

namespace App\Modules\Core\Filament\Resources\FiscalYears;

use App\Modules\Core\Filament\Resources\FiscalYears\Pages\CreateFiscalYear;
use App\Modules\Core\Filament\Resources\FiscalYears\Pages\EditFiscalYear;
use App\Modules\Core\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Modules\Core\Filament\Resources\FiscalYears\Schemas\FiscalYearForm;
use App\Modules\Core\Filament\Resources\FiscalYears\Tables\FiscalYearsTable;
use App\Modules\Core\Models\FiscalYear;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FiscalYearResource extends Resource
{
    protected static ?string $model = FiscalYear::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Salary Slab & Fiscal Year';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function form(Schema $schema): Schema
    {
        return FiscalYearForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FiscalYearsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiscalYears::route('/'),
            'create' => CreateFiscalYear::route('/create'),
            'edit' => EditFiscalYear::route('/{record}/edit'),
        ];
    }
}
