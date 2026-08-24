<?php

namespace App\Modules\ConstructionField\Filament\Resources\Activities;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionField\Filament\Resources\Activities\Pages\CreateActivity;
use App\Modules\ConstructionField\Filament\Resources\Activities\Pages\EditActivity;
use App\Modules\ConstructionField\Filament\Resources\Activities\Pages\ListActivities;
use App\Modules\ConstructionField\Filament\Resources\Activities\RelationManagers\PredecessorsRelationManager;
use App\Modules\ConstructionField\Filament\Resources\Activities\Schemas\ActivityForm;
use App\Modules\ConstructionField\Filament\Resources\Activities\Tables\ActivitiesTable;
use App\Modules\ConstructionField\Models\ProgrammeActivity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The programme — `docs/construction-management-plan.md` §13.
 *
 * **This is a register, not a scheduler.** There is no Gantt editor, no critical-path calculation and no way to make one
 * date move another, because the accepted programme was produced in P6 or Asta and that is the contractual document.
 * What this screen is for is the four things §13 says contract administration actually needs from a programme: a
 * milestone with a contractual date, planned against actual, somewhere to hang a delay event, and an identifier an RFI
 * or a submittal can point at.
 *
 * **The badge counts unexcused lateness on priced milestones** — days a contract milestone is late against the accepted
 * programme with no extension of time awarded against it. That is liquidated damages accruing, and it is the number a
 * programme is worth storing for.
 */
class ActivityResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ProgrammeActivity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 30;

    protected static ?string $label = 'Programme activity';

    protected static ?string $pluralLabel = 'Programme';

    /*
     * **There is deliberately no navigation badge here, and the rule behind that is worth stating once.**
     *
     * Phase 9e established that a badge must be a single indexed count or not exist, because it renders on every page in
     * the panel. Phase 9g had to add the second half: **a badge earns its per-page query only where the failure it warns
     * about is silent and time-barred** — where not looking today permanently costs money.
     *
     * `construction_field` ships five registers and each of them wanted one. Five counts on every page in the
     * application is a real cost, and `PanelPerformanceTest` priced it. Two survive: §13's delay-notice clock and
     * §16.2's overdue RFIs, both of which are contractual windows that *close* — a missed notice is gone and no screen
     * can recover it.
     *
     * This register is not that. Its figure is money **held or accruing**, visible whenever somebody opens the
     * certificate or the programme, and not extinguished by nobody looking today. The register's own columns and filters
     * carry it.
     */

    public static function getEloquentQuery(): Builder
    {
        // The delay events are folded into the exposure column on every milestone row.
        return parent::getEloquentQuery()->with(['job', 'delayEvents']);
    }

    public static function form(Schema $schema): Schema
    {
        return ActivityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [PredecessorsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'create' => CreateActivity::route('/create'),
            'edit' => EditActivity::route('/{record}/edit'),
        ];
    }
}
