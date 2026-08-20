<?php

namespace App\Modules\Inventory\Filament\Resources\StockLocations;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Inventory\Filament\Resources\StockLocations\Pages\CreateStockLocation;
use App\Modules\Inventory\Filament\Resources\StockLocations\Pages\EditStockLocation;
use App\Modules\Inventory\Filament\Resources\StockLocations\Pages\ListStockLocations;
use App\Modules\Inventory\Filament\Resources\StockLocations\Schemas\StockLocationForm;
use App\Modules\Inventory\Filament\Resources\StockLocations\Tables\StockLocationsTable;
use App\Modules\Inventory\Models\StockLocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Where stock is — `docs/construction-management-plan.md` §6, `docs/retail-stores-pos-plan.md` §2.1.
 *
 * **One register serving both plans**, which is the decision this table exists to express: a warehouse, a shop, a site
 * store and a van are the same kind of thing to Inventory, and giving each module its own location table would have made
 * on-hand a sum over two dimensions — "wrong at every location and correct in total".
 *
 * A company that keeps stock in one place never needs a row here at all. Nothing is created by default, because a
 * location nobody chose is a row somebody has to work out the meaning of.
 */
class StockLocationResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = StockLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing & Inventory';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 46;

    protected static ?string $label = 'Stock location';

    protected static ?string $pluralLabel = 'Stock locations';

    public static function form(Schema $schema): Schema
    {
        return StockLocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockLocationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockLocations::route('/'),
            'create' => CreateStockLocation::route('/create'),
            'edit' => EditStockLocation::route('/{record}/edit'),
        ];
    }
}
