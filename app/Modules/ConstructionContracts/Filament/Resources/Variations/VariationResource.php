<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Variations;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionContracts\Filament\Resources\Variations\Pages\CreateVariation;
use App\Modules\ConstructionContracts\Filament\Resources\Variations\Pages\EditVariation;
use App\Modules\ConstructionContracts\Filament\Resources\Variations\Pages\ListVariations;
use App\Modules\ConstructionContracts\Filament\Resources\Variations\RelationManagers\VariationItemsRelationManager;
use App\Modules\ConstructionContracts\Filament\Resources\Variations\Schemas\VariationForm;
use App\Modules\ConstructionContracts\Filament\Resources\Variations\Tables\VariationsTable;
use App\Modules\ConstructionContracts\Models\Variation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Variations and change orders — `docs/construction-management-plan.md` §9.
 *
 * One register for both, because they are the same thing under two names. The label follows the contract family
 * on each row rather than the resource, since a company can run FIDIC and AIA jobs side by side — so the plural
 * here stays neutral.
 *
 * The register is where the state machine lives as buttons. What it will not do is let the price-provisional flag
 * be edited: it is set by approving in principle and cleared by approving, because a checkbox that moves money
 * between certified and forecast is one somebody ticks by accident.
 */
class VariationResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Variation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Contracts';

    protected static ?string $recordTitleAttribute = 'variation_number';

    protected static ?int $navigationSort = 20;

    protected static ?string $label = 'Variation';

    protected static ?string $pluralLabel = 'Variations and change orders';

    public static function getEloquentQuery(): Builder
    {
        // The register shows the contract and its job on every row.
        return parent::getEloquentQuery()->with(['contract.job']);
    }

    public static function form(Schema $schema): Schema
    {
        return VariationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VariationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [VariationItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVariations::route('/'),
            'create' => CreateVariation::route('/create'),
            'edit' => EditVariation::route('/{record}/edit'),
        ];
    }
}
