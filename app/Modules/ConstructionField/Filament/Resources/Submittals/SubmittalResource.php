<?php

namespace App\Modules\ConstructionField\Filament\Resources\Submittals;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionField\Filament\Resources\Submittals\Pages\CreateSubmittal;
use App\Modules\ConstructionField\Filament\Resources\Submittals\Pages\EditSubmittal;
use App\Modules\ConstructionField\Filament\Resources\Submittals\Pages\ListSubmittals;
use App\Modules\ConstructionField\Filament\Resources\Submittals\RelationManagers\ReviewsRelationManager;
use App\Modules\ConstructionField\Filament\Resources\Submittals\Schemas\SubmittalForm;
use App\Modules\ConstructionField\Filament\Resources\Submittals\Tables\SubmittalsTable;
use App\Modules\ConstructionField\Models\Submittal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The submittal register — `docs/construction-management-plan.md` §16.3.
 *
 * **The screen is a schedule control, not a filing cabinet.** Every row shows the date it has to be submitted by,
 * computed backwards from the date the thing is needed on site — and the register's first useful act on a real job is to
 * show how many of those dates have already passed, because nobody did the subtraction when the programme was agreed.
 *
 * The navigation badge counts items already late to submit. Not items outstanding, which is the normal state of a
 * register and a badge that is always lit is a badge nobody reads.
 */
class SubmittalResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Submittal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 20;

    protected static ?string $label = 'Submittal';

    protected static ?string $pluralLabel = 'Submittals';

    /*
     * **There is deliberately no navigation badge, and it is §16.3's own rule that rules one out.**
     *
     * The number worth badging is how many items are already past their computed submit-by date. That date is not a
     * column — the whole argument of §16.3 is that storing it makes it stale the day the programme moves — so counting
     * the late ones needs either a dialect-specific date expression (`julianday` on SQLite, `DATEDIFF` on MySQL, and
     * Phase 9e recorded why that is a query that only works in tests) or a read of every outstanding row.
     *
     * A navigation badge renders on **every page in the panel**, which makes it the one place in this application that
     * can afford neither. The first draft did the read, and `PanelPerformanceTest` caught it as
     * `select * from construction_submittals` in the dashboard's query list — exactly the check working.
     *
     * The figure is not lost: the register's `Submit by` column shows it per row and `SubmittalService::lateToSubmit()`
     * is the report, both on a page somebody opened on purpose.
     */

    public static function getEloquentQuery(): Builder
    {
        // The rounds are folded into two columns on every row — how many, and the reviewer's overrun.
        return parent::getEloquentQuery()->with(['job', 'reviews']);
    }

    public static function form(Schema $schema): Schema
    {
        return SubmittalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubmittalsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ReviewsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmittals::route('/'),
            'create' => CreateSubmittal::route('/create'),
            'edit' => EditSubmittal::route('/{record}/edit'),
        ];
    }
}
