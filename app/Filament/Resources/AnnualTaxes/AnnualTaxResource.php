<?php

namespace App\Filament\Resources\AnnualTaxes;

use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Filament\Resources\AnnualTaxes\Pages\CreateAnnualTax;
use App\Filament\Resources\AnnualTaxes\Pages\EditAnnualTax;
use App\Filament\Resources\AnnualTaxes\Pages\ListAnnualTaxes;
use App\Filament\Resources\AnnualTaxes\Schemas\AnnualTaxForm;
use App\Filament\Resources\AnnualTaxes\Tables\AnnualTaxesTable;
use App\Models\AnnualTax;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AnnualTaxResource extends Resource
{
    use ScopesToAccessibleEmployees;

    protected static ?string $model = AnnualTax::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Taxes';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'employee.employee_id', 'employee.user.name', 'fiscalYear.name'];
    }

    /**
     * Privileged roles see all annual taxes; everyone else sees their own plus
     * those of their reporting downline.
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
        return AnnualTaxForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnnualTaxesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnualTaxes::route('/'),
            'create' => CreateAnnualTax::route('/create'),
            'edit' => EditAnnualTax::route('/{record}/edit'),
        ];
    }
}
