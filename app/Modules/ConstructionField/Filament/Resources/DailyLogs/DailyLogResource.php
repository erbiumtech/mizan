<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\Pages\CreateDailyLog;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\Pages\EditDailyLog;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\Pages\ListDailyLogs;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers\DeliveriesRelationManager;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers\EventsRelationManager;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers\ManpowerRelationManager;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers\PhotosRelationManager;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers\PlantRelationManager;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\Schemas\DailyLogForm;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\Tables\DailyLogsTable;
use App\Modules\ConstructionField\Models\DailyLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The site diary — `docs/construction-management-plan.md` §16.1.
 *
 * **One day per job, and approval locks it.** Those two rules are what make a diary evidence rather than a note, and
 * both are visible on the screen: a second diary for a date is refused with a sentence, and an approved day goes
 * read-only including its manpower, plant and events — which is where the numbers a claim is built from live.
 */
class DailyLogResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = DailyLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?int $navigationSort = 5;

    protected static ?string $label = 'Site diary';

    protected static ?string $pluralLabel = 'Site diary';

    public static function getEloquentQuery(): Builder
    {
        // The register prints man-hours and standing plant per row, both folds over the children, and counts the
        // deliveries and photographs — a count is one query for the page rather than one per row.
        return parent::getEloquentQuery()
            ->with(['job', 'manpower', 'plant', 'events'])
            ->withCount(['deliveries', 'photos']);
    }

    public static function form(Schema $schema): Schema
    {
        return DailyLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DailyLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ManpowerRelationManager::class,
            PlantRelationManager::class,
            DeliveriesRelationManager::class,
            EventsRelationManager::class,
            PhotosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDailyLogs::route('/'),
            'create' => CreateDailyLog::route('/create'),
            'edit' => EditDailyLog::route('/{record}/edit'),
        ];
    }
}
