<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Pages\CreateToolboxTalk;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Pages\EditToolboxTalk;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Pages\ListToolboxTalks;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\RelationManagers\AttendeesRelationManager;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Schemas\ToolboxTalkForm;
use App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Tables\ToolboxTalksTable;
use App\Modules\ConstructionQhse\Models\ToolboxTalk;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Toolbox talks — `docs/construction-management-plan.md` §17.5.
 *
 * **The attendance count is the figure, not the number of talks.** §17.6 counts talks delivered *and attended*, because
 * forty talks to two people each is not a briefed site — and a register that only counted talks would report one.
 *
 * No badge: a talk not given is not a silent failure, it is an absence on a screen somebody opens. The module's three
 * badges are already spent on the things nothing else watches.
 */
class ToolboxTalkResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ToolboxTalk::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Quality & Safety';

    protected static ?string $recordTitleAttribute = 'topic';

    protected static ?int $navigationSort = 40;

    protected static ?string $label = 'Toolbox talk';

    protected static ?string $pluralLabel = 'Toolbox talks';

    public static function getEloquentQuery(): Builder
    {
        // The attendees are folded into the count on every row — which is the column that matters.
        return parent::getEloquentQuery()->with(['job', 'attendees', 'incident']);
    }

    public static function form(Schema $schema): Schema
    {
        return ToolboxTalkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ToolboxTalksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [AttendeesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListToolboxTalks::route('/'),
            'create' => CreateToolboxTalk::route('/create'),
            'edit' => EditToolboxTalk::route('/{record}/edit'),
        ];
    }
}
