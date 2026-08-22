<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\QhseActions;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionQhse\Filament\Resources\QhseActions\Pages\EditQhseAction;
use App\Modules\ConstructionQhse\Filament\Resources\QhseActions\Pages\ListQhseActions;
use App\Modules\ConstructionQhse\Filament\Resources\QhseActions\Schemas\QhseActionForm;
use App\Modules\ConstructionQhse\Filament\Resources\QhseActions\Tables\QhseActionsTable;
use App\Modules\ConstructionQhse\Models\QhseAction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The one actions register — `docs/construction-management-plan.md` §17.4.
 *
 * **This is the screen §17.4 exists to make possible**: everything outstanding, from every QHSE source, in one list.
 * "Four separate action tables produce four *overdue actions* reports that never agree, and the safety manager's one
 * genuinely useful screen becomes a four-way union nobody maintains."
 *
 * There is no create page. An action is raised *against* the finding that produced it — an NCR, an inspection, an
 * incident — because an action with no subject is a task in a quality register, and this is not a task manager. The
 * register lists and works them; the source screens raise them.
 *
 * **The badge counts overdue actions**, and it earns its per-page query by Phase 9g's rule: an action that quietly went
 * past its date is the closest thing in this module to a silent failure, and it is one indexed count against
 * `(job_id, status, due_on)`.
 */
class QhseActionResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = QhseAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Quality & Safety';

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?int $navigationSort = 20;

    protected static ?string $label = 'QHSE action';

    protected static ?string $pluralLabel = 'Actions';

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
        return parent::getEloquentQuery()->with(['job', 'subject']);
    }

    public static function form(Schema $schema): Schema
    {
        return QhseActionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QhseActionsTable::configure($table);
    }

    /** No create page: an action is raised against the finding that produced it. See the class docblock. */
    public static function getPages(): array
    {
        return [
            'index' => ListQhseActions::route('/'),
            'edit' => EditQhseAction::route('/{record}/edit'),
        ];
    }
}
