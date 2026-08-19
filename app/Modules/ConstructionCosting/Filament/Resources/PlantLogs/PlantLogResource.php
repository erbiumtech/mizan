<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantLogs;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Pages\CreatePlantLog;
use App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Pages\EditPlantLog;
use App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Pages\ListPlantLogs;
use App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Schemas\PlantLogForm;
use App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Tables\PlantLogsTable;
use App\Modules\ConstructionCosting\Models\PlantLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What each machine did, day by day — `docs/construction-management-plan.md` §7.3.
 *
 * **An approved log books cost only for an owned machine.** A hired one's cost is its supplier invoice; its log is what
 * checks that invoice. The *Books cost* column says which a row is, because two rows that look identical and behave
 * differently is exactly the sort of thing nobody notices until a job is charged twice for one excavator.
 */
class PlantLogResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = PlantLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?int $navigationSort = 55;

    protected static ?string $label = 'Plant log';

    protected static ?string $pluralLabel = 'Plant logs';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['plantItem', 'job', 'costCode', 'operator']);
    }

    public static function form(Schema $schema): Schema
    {
        return PlantLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlantLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlantLogs::route('/'),
            'create' => CreatePlantLog::route('/create'),
            'edit' => EditPlantLog::route('/{record}/edit'),
        ];
    }
}
