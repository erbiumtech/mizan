<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Pages\CreateSitePersonnel;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Pages\EditSitePersonnel;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Pages\ListSitePersonnel;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\RelationManagers\CompetenciesRelationManager;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Schemas\SitePersonnelForm;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Tables\SitePersonnelTable;
use App\Modules\ConstructionQhse\Models\SitePersonnel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The induction and competency register — `docs/construction-management-plan.md` §17.5.
 *
 * **A name is all that is ever required**, because "most attendees on most sites are a subcontractor's labourers". A
 * register that asked for more would list the people who happened to be on the payroll.
 *
 * **The `Cleared` column is what the register is for**: inducted, and no mandatory ticket lapsed. There is deliberately
 * no navigation badge — the module already carries three, and this figure is the first column of a screen somebody opens
 * at the gate. The daily `construction:check-competency-expiry` run is what makes the lapses audible, which is the
 * *silent* half the badge rule is about.
 */
class SitePersonnelResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = SitePersonnel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Quality & Safety';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 35;

    protected static ?string $label = 'Site personnel';

    protected static ?string $pluralLabel = 'Site personnel';

    public static function getEloquentQuery(): Builder
    {
        // The tickets are folded into the cleared-to-work column on every row.
        return parent::getEloquentQuery()->with(['job', 'competencies']);
    }

    public static function form(Schema $schema): Schema
    {
        return SitePersonnelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SitePersonnelTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [CompetenciesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSitePersonnel::route('/'),
            'create' => CreateSitePersonnel::route('/create'),
            'edit' => EditSitePersonnel::route('/{record}/edit'),
        ];
    }
}
