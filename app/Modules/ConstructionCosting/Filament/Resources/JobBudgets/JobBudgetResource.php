<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\JobBudgets;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Pages\CreateJobBudget;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Pages\EditJobBudget;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Pages\ListJobBudgets;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\RelationManagers\LinesRelationManager;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Schemas\JobBudgetForm;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Tables\JobBudgetsTable;
use App\Modules\ConstructionCosting\Models\JobBudget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The job budget and its versions — `docs/construction-management-plan.md` §3.5.
 *
 * **Two flags, not one, and the screen shows both because they answer different questions.** The *current* budget
 * is what the cost report compares actuals to and moves every time a variation is approved. The *baseline* is what
 * earned value measures against and must not move once work has been claimed. On a job with no variations they are
 * the same version; the moment one is approved they diverge, and a screen that showed only "the budget" would make
 * every schedule variance on the job meaningless without saying so.
 *
 * Every rule here — draft-only editing, the empty-version refusal, the re-baselining refusal — lives in
 * `BudgetService`, not in this resource. §3's reason holds: a budget will also arrive by import and by copy from a
 * previous version, and a rule enforced on one screen is a rule the other two walk past.
 */
class JobBudgetResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = JobBudget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 35;

    protected static ?string $label = 'Job budget';

    protected static ?string $pluralLabel = 'Job budgets';

    public static function getEloquentQuery(): Builder
    {
        // Everything the list needs in one query. The counts and the sum are aggregates rather than accessors on
        // purpose: `isTimePhased()` and `budgetAtCompletion()` would each be a query per row, and a job with a
        // three-year history carries a dozen versions.
        return parent::getEloquentQuery()
            ->with('job')
            ->withCount('lines')
            ->withCount(['lines as phased_lines_count' => fn ($query) => $query->whereNotNull('period_start')])
            ->withSum('lines', 'amount');
    }

    public static function form(Schema $schema): Schema
    {
        return JobBudgetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobBudgetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobBudgets::route('/'),
            'create' => CreateJobBudget::route('/create'),
            'edit' => EditJobBudget::route('/{record}/edit'),
        ];
    }
}
