<?php

namespace App\Modules\ConstructionField\Filament\Resources\PunchLists;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\Pages\CreatePunchList;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\Pages\EditPunchList;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\Pages\ListPunchLists;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\RelationManagers\ItemsRelationManager;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\Schemas\PunchListForm;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\Tables\PunchListsTable;
use App\Modules\ConstructionField\Models\PunchItem;
use App\Modules\ConstructionField\Models\PunchList;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Punch and snag lists — `docs/construction-management-plan.md` §16.4.
 *
 * **The badge counts open items that stop the employer taking the building over**, because that is the number attached
 * to money: §11's AIA release holds back the cost of rectifying exactly those, and releasing the whole balance on a job
 * with fifty of them is money that does not come back.
 *
 * Not the count of open items, which on any real job before handover is in the hundreds and would light the badge for
 * six months.
 */
class PunchListResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = PunchList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 25;

    protected static ?string $label = 'Punch list';

    protected static ?string $pluralLabel = 'Punch lists';

    public static function getNavigationBadge(): ?string
    {
        $blocking = PunchItem::query()->blockingCompletion()->count();

        return $blocking > 0 ? (string) $blocking : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        // The open count and the blocking count are folds over the items, printed on every row.
        return parent::getEloquentQuery()->with(['job', 'location', 'items']);
    }

    public static function form(Schema $schema): Schema
    {
        return PunchListForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PunchListsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPunchLists::route('/'),
            'create' => CreatePunchList::route('/create'),
            'edit' => EditPunchList::route('/{record}/edit'),
        ];
    }
}
