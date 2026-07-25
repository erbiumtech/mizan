<?php

namespace App\Filament\Resources\Payslips;

use App\Filament\Resources\Payslips\Pages\CreatePayslip;
use App\Filament\Resources\Payslips\Pages\EditPayslip;
use App\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Filament\Resources\Payslips\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\Payslips\Schemas\PayslipForm;
use App\Filament\Resources\Payslips\Tables\PayslipsTable;
use App\Models\Payslip;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PayslipResource extends Resource
{
    protected static ?string $model = Payslip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Payslip';

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
     * Role-based scoping, mirroring Nova indexQuery(): plain Employees see only
     * their own payslips; Admin/Accountant/Manager/CEO (and other roles) see all.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user
            && $user->hasRole('Employee')
            && ! $user->hasAnyRole(['Administrator', 'Accountant', 'Manager', 'CEO'])) {
            $query->whereHas('employee', fn ($q) => $q->where('user_id', $user->id));
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
