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

    /**
     * How many are already past their computed submit-by date.
     *
     * Counted in PHP because the date is not a column — which is the whole of §16.3. The query is narrowed to the ones
     * still awaiting submission first, so this reads a short list rather than the register.
     */
    public static function getNavigationBadge(): ?string
    {
        $late = Submittal::query()
            ->awaitingSubmission()
            ->get()
            ->filter(fn (Submittal $submittal): bool => $submittal->isLateToSubmit())
            ->count();

        return $late > 0 ? (string) $late : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

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
