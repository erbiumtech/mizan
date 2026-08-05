<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies;

use App\Modules\Core\Filament\Platform\Resources\Companies\Pages\CreateCompany;
use App\Modules\Core\Filament\Platform\Resources\Companies\Pages\EditCompany;
use App\Modules\Core\Filament\Platform\Resources\Companies\Pages\ListCompanies;
use App\Modules\Core\Filament\Platform\Resources\Companies\Schemas\CompanyForm;
use App\Modules\Core\Filament\Platform\Resources\Companies\Tables\CompaniesTable;
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

    /**
     * Under the company, not beside it.
     *
     * Members, roles and licences are all per company, and each of them read as something
     * else when listed flat: roles across companies look like duplicates of each other,
     * and a membership row means nothing without saying whose.
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\MembersRelationManager::class,
            RelationManagers\RolesRelationManager::class,
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
