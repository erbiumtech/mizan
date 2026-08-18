<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Pages\EditGoodsReceipt;
use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\RelationManagers\ReceiptLinesRelationManager;
use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Schemas\GoodsReceiptForm;
use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Tables\GoodsReceiptsTable;
use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Deliveries — `docs/construction-management-plan.md` §5, "where cost first touches the job".
 *
 * **The screen site fills in.** Posting a receipt relieves the order and puts accrued cost on the job, which is what
 * stops a month end understating everything delivered but not yet invoiced.
 *
 * It does **not** write a stock movement, and a line destined for a site store is refused with a message naming what
 * is missing: that needs `stock_locations`, which §6 assigns to Inventory and a later phase delivers. Accepting it
 * would cost the material as though it had been stocked, and materials-on-site would be wrong with nothing saying so.
 */
class GoodsReceiptResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = GoodsReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 46;

    protected static ?string $label = 'Goods receipt';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['commitment', 'lines']);
    }

    public static function form(Schema $schema): Schema
    {
        return GoodsReceiptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoodsReceiptsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ReceiptLinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoodsReceipts::route('/'),
            'create' => CreateGoodsReceipt::route('/create'),
            'edit' => EditGoodsReceipt::route('/{record}/edit'),
        ];
    }
}
