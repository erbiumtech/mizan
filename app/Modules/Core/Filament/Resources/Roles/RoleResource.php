<?php

namespace App\Modules\Core\Filament\Resources\Roles;

use App\Modules\Core\Filament\Resources\Roles\Pages\CreateRole;
use App\Modules\Core\Filament\Resources\Roles\Pages\EditRole;
use App\Modules\Core\Filament\Resources\Roles\Pages\ListRoles;
use App\Modules\Core\Filament\Resources\Roles\Schemas\RoleForm;
use App\Modules\Core\Filament\Resources\Roles\Tables\RolesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Access Control';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    /**
     * Only this company's roles.
     *
     * A role belongs to one company — every row carries a `company_id` (spatie teams) —
     * so provisioning a second company correctly creates a second set of five. Queried
     * without a filter, though, the list showed every company's, and each name appeared
     * once per company with nothing on screen to tell them apart: creating a company
     * looked exactly like it had duplicated the roles.
     *
     * Filtered here rather than on the table, so that it bounds the record the edit page
     * resolves as well. Otherwise the list is a display detail and another company's role
     * is still editable by its id.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where(
            config('permission.column_names.team_foreign_key', 'company_id'),
            static::currentCompanyId(),
        );
    }

    /**
     * The company a role created or listed here belongs to.
     *
     * Read from the panel rather than from spatie's registrar: the registrar's team id is
     * request state that a seeder or a queued job may have left null, and a role stamped
     * null belongs to no company and is reachable by nobody — which is what a company's
     * roles going missing looks like.
     */
    public static function currentCompanyId(): ?int
    {
        return \Filament\Facades\Filament::getTenant()?->getKey()
            ?? \App\Modules\Core\Models\Company::current()?->getKey();
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
