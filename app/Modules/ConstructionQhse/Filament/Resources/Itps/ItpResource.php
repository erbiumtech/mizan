<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Itps;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\Pages\CreateItp;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\Pages\EditItp;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\Pages\ListItps;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\RelationManagers\ActivitiesRelationManager;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\Schemas\ItpForm;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\Tables\ItpsTable;
use App\Modules\ConstructionQhse\Models\Itp;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Inspection and test plans — `docs/construction-management-plan.md` §17.1.
 *
 * **A controlled document, not a checklist.** A certification body asks which revision was in force when a given
 * inspection was carried out, so a plan is drafted, issued, approved and *superseded* — never edited once people are
 * working to it.
 *
 * The value is in the rows, and §17.1 says which column carries it: hold, witness or review. "Collapsing them into a
 * checkbox turns the document into a formality."
 *
 * No navigation badge, by the rule Phase 9g settled: a badge is earned only where the failure it warns about is silent
 * and time-barred. An unapproved ITP is visible the moment somebody opens this register.
 */
class ItpResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Itp::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Quality & Safety';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 5;

    protected static ?string $label = 'Inspection and test plan';

    protected static ?string $pluralLabel = 'ITPs';

    public static function getEloquentQuery(): Builder
    {
        // The hold-point count is a fold over the rows, printed on every line of the register.
        return parent::getEloquentQuery()->with(['job', 'activities']);
    }

    public static function form(Schema $schema): Schema
    {
        return ItpForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItpsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItps::route('/'),
            'create' => CreateItp::route('/create'),
            'edit' => EditItp::route('/{record}/edit'),
        ];
    }
}
