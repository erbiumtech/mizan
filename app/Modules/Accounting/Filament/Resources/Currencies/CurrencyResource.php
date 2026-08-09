<?php

namespace App\Modules\Accounting\Filament\Resources\Currencies;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\Currencies\Pages\CreateCurrency;
use App\Modules\Accounting\Filament\Resources\Currencies\Pages\EditCurrency;
use App\Modules\Accounting\Filament\Resources\Currencies\Pages\ListCurrencies;
use App\Modules\Accounting\Filament\Resources\Currencies\RelationManagers\RatesRelationManager;
use App\Modules\Accounting\Filament\Resources\Currencies\Schemas\CurrencyForm;
use App\Modules\Accounting\Filament\Resources\Currencies\Tables\CurrenciesTable;
use App\Modules\Accounting\Models\Currency;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The currencies this company deals in, and what they were worth when.
 *
 * Which one the books are kept in is set once, in Company Settings, because it is what
 * every amount in the ledger means. This is where the others live, and where their
 * rates are recorded.
 */
class CurrencyResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Currency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return CurrencyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurrenciesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [RatesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurrencies::route('/'),
            'create' => CreateCurrency::route('/create'),
            'edit' => EditCurrency::route('/{record}/edit'),
        ];
    }
}
