<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantItems;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Pages\CreatePlantItem;
use App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Pages\EditPlantItem;
use App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Pages\ListPlantItems;
use App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Schemas\PlantItemForm;
use App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Tables\PlantItemsTable;
use App\Modules\ConstructionCosting\Models\PlantItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The fleet — `docs/construction-management-plan.md` §7.3.
 *
 * **Ownership is the column that matters**, because it decides whether a machine's logs book cost. An owned machine has
 * no invoice, so its log *is* the cost — internal hire, which §11 credits to Plant Internal Hire Recovery. A hired
 * machine's cost is its supplier invoice, so its log writes nothing and becomes the check against that invoice: the
 * *Check against invoices* action on each hired row is §7.3's two-way match.
 */
class PlantItemResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = PlantItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 54;

    protected static ?string $label = 'Plant';

    protected static ?string $pluralLabel = 'Plant';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'defaultCostCode', 'commitment']);
    }

    public static function form(Schema $schema): Schema
    {
        return PlantItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlantItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlantItems::route('/'),
            'create' => CreatePlantItem::route('/create'),
            'edit' => EditPlantItem::route('/{record}/edit'),
        ];
    }
}
