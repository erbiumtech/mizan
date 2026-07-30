<?php

namespace App\Modules\Core\Filament\Resources\Companies;

use App\Modules\Core\Filament\Resources\Companies\Pages\CreateCompany;
use App\Modules\Core\Filament\Resources\Companies\Pages\EditCompany;
use App\Modules\Core\Filament\Resources\Companies\Pages\ListCompanies;
use App\Modules\Core\Filament\Resources\Companies\Schemas\CompanyForm;
use App\Modules\Core\Filament\Resources\Companies\Tables\CompaniesTable;
use App\Modules\Core\Models\Company;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Access Control';

    protected static ?string $recordTitleAttribute = 'name';

    /** Company management is a super-admin-only feature. */
    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
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
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
