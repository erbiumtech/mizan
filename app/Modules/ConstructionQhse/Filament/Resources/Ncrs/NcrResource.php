<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Ncrs;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Pages\CreateNcr;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Pages\EditNcr;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Pages\ListNcrs;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\RelationManagers\ActionsRelationManager;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Schemas\NcrForm;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Tables\NcrsTable;
use App\Modules\ConstructionQhse\Models\Ncr;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Non-conformance — `docs/construction-management-plan.md` §17.2.
 *
 * **Nothing on this screen withholds money.** The strongest thing it does is *propose* a deduction; the row appears on a
 * certificate only when somebody on the certification side takes the offer up and signs for it. §17.2: "a deduction
 * appearing on a certificate that nobody decided on is the fastest available route to a dispute, and it will be the
 * contractor's dispute, because the client's copy has already left the building."
 *
 * The badge counts **critical NCRs still open**. By Phase 9g's rule a badge is earned where the failure is silent and
 * costly — a critical nonconformity is one affecting structural adequacy, safety or a statutory requirement, and one
 * sitting unlooked-at is the thing this whole module exists to prevent.
 */
class NcrResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Ncr::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Quality & Safety';

    protected static ?string $recordTitleAttribute = 'ncr_number';

    protected static ?int $navigationSort = 15;

    protected static ?string $label = 'Non-conformance';

    protected static ?string $pluralLabel = 'NCRs';

    /*
     * **No navigation badge, and the rule is the one Phase 9g settled with the word that does the work in it: *silent*.**
     *
     * A badge costs a query on every page in the panel, so it is earned only where not looking today costs something
     * nobody can see. §13's notice clock qualifies — a window closes and no screen recovers it. §17.1's hold point
     * awaiting release qualifies — work is standing still for want of a signature nobody knows is missing.
     *
     * This register does not, and the reason is that it is *loud*: whatever is worst here is the first row on this
     * screen, and this screen is one somebody opens. `PanelPerformanceTest` priced the alternative — two more badges in
     * this module put the reports hub over its query budget, and raising the budget to keep them would have been
     * spending every page in the application on a number already visible on its own.
     */

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['job', 'location', 'inspection']);
    }

    public static function form(Schema $schema): Schema
    {
        return NcrForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NcrsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ActionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNcrs::route('/'),
            'create' => CreateNcr::route('/create'),
            'edit' => EditNcr::route('/{record}/edit'),
        ];
    }
}
