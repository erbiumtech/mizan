<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Workers;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\Workers\Pages\CreateWorker;
use App\Modules\ConstructionCosting\Filament\Resources\Workers\Pages\EditWorker;
use App\Modules\ConstructionCosting\Filament\Resources\Workers\Pages\ListWorkers;
use App\Modules\ConstructionCosting\Filament\Resources\Workers\Schemas\WorkerForm;
use App\Modules\ConstructionCosting\Filament\Resources\Workers\Tables\WorkersTable;
use App\Modules\ConstructionCosting\Models\Worker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Everybody who works on a site — `docs/construction-management-plan.md` §7.1.
 *
 * **This register exists because on a site most hands are not employees.** §7.1 states the alternative and rejects it:
 * requiring an `Employee` row per labourer would make the HR module a hard dependency of cost control, and would put
 * three hundred people who are not employed into the HR register, where Leave, Payroll and Lifecycle would then all
 * see them.
 *
 * So this screen holds employees, daily-wage hands and gang-supplied labour side by side, and the *Engaged as* column
 * is what tells them apart. A company with no HR module gets the whole register regardless (§18.1).
 */
class WorkerResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Worker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 51;

    protected static ?string $label = 'Worker';

    protected static ?string $pluralLabel = 'Workers';

    public static function getEloquentQuery(): Builder
    {
        // The trade and the supplier are on every row; loading them here is what keeps a gang list of three hundred
        // from being six hundred queries (§18.3).
        return parent::getEloquentQuery()->with(['trade', 'supplier']);
    }

    public static function form(Schema $schema): Schema
    {
        return WorkerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkers::route('/'),
            'create' => CreateWorker::route('/create'),
            'edit' => EditWorker::route('/{record}/edit'),
        ];
    }
}
