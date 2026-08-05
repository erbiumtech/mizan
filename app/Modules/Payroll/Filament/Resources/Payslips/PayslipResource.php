<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\CreatePayslip;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\EditPayslip;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Modules\Payroll\Filament\Resources\Payslips\RelationManagers\CommentsRelationManager;
use App\Modules\Payroll\Filament\Resources\Payslips\Schemas\PayslipForm;
use App\Modules\Payroll\Filament\Resources\Payslips\Tables\PayslipsTable;
use App\Modules\Payroll\Models\Payslip;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PayslipResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = Payslip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getGloballySearchableAttributes(): array
    {
        // Mirrors Nova searchableColumns(): id, month, employee.employee_id,
        // employee.user.name, fiscalYear.name.
        return [
            'id',
            'month',
            'employee.employee_id',
            'employee.user.name',
            'fiscalYear.name',
        ];
    }

    /**
     * Admin/Accountant/Manager/CEO see all payslips; everyone else sees their
     * own plus those of their reporting downline.
     */
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
        return PayslipForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayslipsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CommentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayslips::route('/'),
            'create' => CreatePayslip::route('/create'),
            'edit' => EditPayslip::route('/{record}/edit'),
        ];
    }
}
