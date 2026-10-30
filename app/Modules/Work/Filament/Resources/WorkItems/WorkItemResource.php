<?php

namespace App\Modules\Work\Filament\Resources\WorkItems;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Work\Filament\Resources\WorkItems\Pages\CreateWorkItem;
use App\Modules\Work\Filament\Resources\WorkItems\Pages\EditWorkItem;
use App\Modules\Work\Filament\Resources\WorkItems\Pages\ListWorkItems;
use App\Modules\Work\Filament\Resources\WorkItems\Schemas\WorkItemForm;
use App\Modules\Work\Filament\Resources\WorkItems\Tables\WorkItemsTable;
use App\Modules\Work\Models\WorkItem;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Work items — docs/project-management-plan.md §3.
 *
 * The list is what Phase 1 ships; the board that draws the same query sideways is Phase 2. Filed
 * under *Projects* because a work item and the project it belongs to are read on the same visit.
 */
class WorkItemResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = WorkItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('Work Items');
    }

    /** What is open and assigned to nobody — the number a standup actually asks about. */
    /** The query behind the badge. Batched with every other one on the page — see NavigationBadge. */
    public static function badgeQuery(): ?Builder
    {
        return static::getEloquentQuery()
            ->open()
            ->whereNull('assignee_employee_id');
    }

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['project', 'status']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['key', 'title'];
    }

    public static function form(Schema $schema): Schema
    {
        return WorkItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkItems::route('/'),
            'create' => CreateWorkItem::route('/create'),
            'edit' => EditWorkItem::route('/{record}/edit'),
        ];
    }
}
