<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Pages\CreateMaterialIssue;
use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Pages\EditMaterialIssue;
use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Pages\ListMaterialIssues;
use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\RelationManagers\IssueLinesRelationManager;
use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Schemas\MaterialIssueForm;
use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Tables\MaterialIssuesTable;
use App\Modules\ConstructionCosting\Models\MaterialIssue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Dockets out of a site store — `docs/construction-management-plan.md` §6.
 *
 * **Absent without Inventory**, because an issue *is* a stock movement and there is nothing smaller to degrade to. §6's
 * direct-to-site path costs material on receipt and is what most contractors use for everything.
 *
 * **Posting adds no cost.** The receipt costed the material; this moves that cost from the code it arrived on to the
 * code it was used on. The register shows the docket's value so it is clear what moved, not what was added.
 */
class MaterialIssueResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = MaterialIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 56;

    protected static ?string $label = 'Material issue';

    protected static ?string $pluralLabel = 'Material issues';

    /** An issue is a stock movement, so without Inventory there is no such document (§18.1). */
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && modules()->enabled('inventory');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['store', 'issuedByWorker', 'receivedByWorker']);
    }

    public static function form(Schema $schema): Schema
    {
        return MaterialIssueForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaterialIssuesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [IssueLinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaterialIssues::route('/'),
            'create' => CreateMaterialIssue::route('/create'),
            'edit' => EditMaterialIssue::route('/{record}/edit'),
        ];
    }
}
