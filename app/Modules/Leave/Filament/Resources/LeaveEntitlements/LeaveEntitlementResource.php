<?php

namespace App\Modules\Leave\Filament\Resources\LeaveEntitlements;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Leave\Filament\Resources\LeaveEntitlements\Pages\ListLeaveEntitlements;
use App\Modules\Leave\Filament\Resources\LeaveEntitlements\Schemas\LeaveEntitlementForm;
use App\Modules\Leave\Filament\Resources\LeaveEntitlements\Tables\LeaveEntitlementsTable;
use App\Modules\Leave\Models\LeaveEntitlement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Balances, and the adjustments that move them.
 *
 * There is no edit page on purpose. The three credit columns are written by the
 * open-year command and the year-end reset, and typing over them is how a balance
 * stops being reconstructable — a correction is an *adjustment row*, which is what
 * that table exists for and what the row action here creates. `opening_days` is the
 * one exception, editable at set-up, which is why creating a row by hand is allowed.
 */
class LeaveEntitlementResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = LeaveEntitlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $modelLabel = 'Leave balance';

    protected static ?string $pluralModelLabel = 'Leave balances';

    protected static ?int $navigationSort = 30;

    /** Own record and downline, the same scoping every employee-keyed resource uses. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return LeaveEntitlementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveEntitlementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveEntitlements::route('/'),
        ];
    }
}
