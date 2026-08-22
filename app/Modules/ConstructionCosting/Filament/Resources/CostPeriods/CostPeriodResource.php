<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\CostPeriods;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\CostPeriods\Pages\ListCostPeriods;
use App\Modules\ConstructionCosting\Filament\Resources\CostPeriods\Tables\CostPeriodsTable;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The cost months — `docs/construction-management-plan.md` §3.4, and where §4.1's posting is run from.
 *
 * **No create and no edit, on purpose.** A period is created by the first cost that lands in the month
 * (`CostPeriod::forDate()`), because §3.4's argument holds: "a job's first cost is what says the month exists, and a
 * table of empty future periods is a list of things that look closable." A form offering to create March 2029 would be
 * offering to create something closable that nothing has happened in.
 *
 * **And no reopen, ever.** §3.4 is explicit: reopening a signed-off period to slot one invoice in invalidates the WIP
 * snapshot, the client certificate and the GL summary that all depended on that period's total. A late cost lands in the
 * open period with `incurred_on` preserved and `is_late_for_period` set, which is a fact the Late Costs report can show
 * rather than a silent restatement of a month somebody signed.
 *
 * What this screen is for is the two acts that *are* period-level: posting what construction owes the general ledger
 * (§4.1), and — from Phase 11e — closing the month.
 */
class CostPeriodResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = CostPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?int $navigationSort = 59;

    protected static ?string $label = 'Cost period';

    protected static ?string $pluralLabel = 'Cost periods';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return CostPeriodsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostPeriods::route('/'),
        ];
    }
}
