<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseClaims;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\Pages\CreateExpenseClaim;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\Pages\EditExpenseClaim;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\Pages\ListExpenseClaims;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\Schemas\ExpenseClaimForm;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\Tables\ExpenseClaimsTable;
use App\Modules\Expenses\Models\ExpenseClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ExpenseClaimResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = ExpenseClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?string $modelLabel = 'Expense claim';

    /**
     * Own claims, and a manager's downline. The same scoping payslips use, because
     * a claim says what somebody spent their evening on and is no more public than
     * their salary.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    /** Pending claims, so an approver sees there is something waiting. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()->where('status', ExpenseClaim::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseClaimForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseClaimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseClaims::route('/'),
            'create' => CreateExpenseClaim::route('/create'),
            'edit' => EditExpenseClaim::route('/{record}/edit'),
        ];
    }
}
