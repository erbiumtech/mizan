<?php

namespace App\Modules\Invoicing\Filament\Resources\TaxRates;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Pages\CreateTaxRate;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Pages\EditTaxRate;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Pages\ListTaxRates;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Schemas\TaxRateForm;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Tables\TaxRatesTable;
use App\Modules\Invoicing\Models\TaxRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TaxRateResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = TaxRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing & Inventory';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Tax rate';

    public static function form(Schema $schema): Schema
    {
        return TaxRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxRatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxRates::route('/'),
            'create' => CreateTaxRate::route('/create'),
            'edit' => EditTaxRate::route('/{record}/edit'),
        ];
    }
}
