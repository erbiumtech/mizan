<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\CostEntries;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Pages\CreateCostEntry;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Pages\EditCostEntry;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Pages\ListCostEntries;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Schemas\CostEntryForm;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Tables\CostEntriesTable;
use App\Modules\ConstructionCosting\Models\CostEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Manual cost entry — the "recorded by hand" half of Phase 2's exit condition.
 *
 * Most cost will arrive from a goods receipt, a subcontract certificate or a labour run in later phases. This
 * screen is what makes the ledger usable before any of those exist, and stays useful afterwards for the costs
 * that have no document: a burden charge, an allocation, a correction.
 *
 * **Editing and reversing are not the same act**, and the screen reflects that. An entry in an open period is
 * editable; once its period closes or it reaches the general ledger the row hardens and the only correction is
 * a reversal — §3.3, enforced in `CostLedger` rather than here, because a form rule is walked past by every
 * other caller.
 */
class CostEntryResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = CostEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?int $navigationSort = 30;

    protected static ?string $label = 'Job cost';

    protected static ?string $pluralLabel = 'Job cost';

    public static function getEloquentQuery(): Builder
    {
        // The list shows job, code and period on every row, so all three are eager-loaded: a cost register runs
        // to tens of thousands of rows and one query per row per relation is the classic way this page dies.
        return parent::getEloquentQuery()->with(['job', 'costCode']);
    }

    public static function form(Schema $schema): Schema
    {
        return CostEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostEntries::route('/'),
            'create' => CreateCostEntry::route('/create'),
            'edit' => EditCostEntry::route('/{record}/edit'),
        ];
    }
}
