<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Requisitions;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Pages\CreateRequisition;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Pages\EditRequisition;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\RelationManagers\RequisitionLinesRelationManager;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Schemas\RequisitionForm;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Tables\RequisitionsTable;
use App\Modules\ConstructionCosting\Models\Requisition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What site asked for — `docs/construction-management-plan.md` §5's demand document.
 *
 * **The screen site staff actually use.** It is the only construction register they can create in, and deliberately
 * so: the demand comes from the people who need the material, and a requisition raised only by the commercial office
 * is a purchase order with an extra step.
 *
 * Nothing here commits money. Approving says the need is real; the order that follows is what commits, and only when
 * it is issued.
 */
class RequisitionResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Requisition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 44;

    protected static ?string $label = 'Requisition';

    protected static ?string $pluralLabel = 'Requisitions';

    public static function getEloquentQuery(): Builder
    {
        // The register shows the job and the outstanding quantity per row, and outstanding is a fold over the lines
        // and the order lines that name them — so all three are loaded once (§18.3).
        return parent::getEloquentQuery()->with(['job', 'lines.commitmentLines.commitment']);
    }

    public static function form(Schema $schema): Schema
    {
        return RequisitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RequisitionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [RequisitionLinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRequisitions::route('/'),
            'create' => CreateRequisition::route('/create'),
            'edit' => EditRequisition::route('/{record}/edit'),
        ];
    }
}
