<?php

namespace App\Modules\Payroll\Filament\Resources\PayrollRuns;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Payroll\Filament\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Modules\Payroll\Filament\Resources\PayrollRuns\Tables\PayrollRunsTable;
use App\Modules\Payroll\Models\PayrollRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The months of payroll, and which of them have been agreed.
 *
 * List only: a run is created by payroll when the month's first payslip is raised,
 * and the only things done to it are signing it off and, with a reason, opening it
 * again.
 */
class PayrollRunResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = PayrollRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'month';

    protected static ?string $modelLabel = 'Payroll month';

    protected static ?string $pluralModelLabel = 'Payroll months';

    public static function table(Table $table): Table
    {
        return PayrollRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollRuns::route('/'),
        ];
    }
}
