<?php

namespace App\Modules\Core\Filament\Platform\Resources\Roles;

use App\Modules\Core\Filament\Platform\Resources\Roles\Pages\ListPlatformRoles;
use App\Modules\Core\Filament\Platform\Resources\Roles\Tables\PlatformRolesTable;
use App\Modules\Core\Models\Company;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Every role in the installation, and which company each one belongs to.
 *
 * The cross-company view the platform panel had no answer for: roles were reachable only
 * under one company at a time (CompanyResource's RolesRelationManager), so "which companies
 * are missing a role, and where did this extra one come from?" meant opening each company in
 * turn. Companies, Permissions and Users are all listed installation-wide here; roles were
 * the gap in that row.
 *
 * **Listing only, on purpose.** Roles are created by RoleSeeder — from the company's own
 * screen, or from the action on the company record — and a role's permissions are edited on
 * the company panel, because RoleForm offers only the permission groups belonging to modules
 * that company has licensed (see RoleForm::groupIsVisible). There is no current company
 * here, so that form would offer every permission in the installation, including ones for
 * modules the company cannot reach: rights granted against a feature nobody there can use.
 * The row action opens the role where that context exists instead.
 *
 * Landlord-backed, as everything on this panel must be — `roles` is a landlord table, keyed
 * to a company by spatie's team column rather than by living in its database. See
 * PlatformPanelIsLandlordOnlyTest.
 */
class PlatformRoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Access Control';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'role';

    protected static ?string $slug = 'roles';

    // No navigationSort, like its neighbours: the group is ordered by label, which puts this
    // between Permissions and Users. An explicit sort here put it last instead, because the
    // other three have none to be sorted against.

    public static function canAccess(): bool
    {
        // Beside the panel's own front door, for the reason PermissionResource gives: this
        // class is one rename away from being discovered by the company panel again.
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * The owning company's name, as a select subquery.
     *
     * A role's company is spatie's team column, not a relation — there is no `company()` on
     * spatie's Role, and giving it one means replacing the model for the whole installation.
     *
     * A subquery rather than the left join this first used: `roles` and `companies` share
     * `name`, `created_at` and `id`, so under a join every unqualified column in the table —
     * including the ones Filament generates for its own search and sort — becomes "ambiguous
     * column name". Correlated here instead, nothing is joined, so the role's own columns
     * stay unambiguous and a role whose company row has gone still lists, with a null name.
     * That row matters: it is reachable by nobody, and this is the screen that shows it.
     */
    public static function getEloquentQuery(): Builder
    {
        $teamKey = config('permission.column_names.team_foreign_key', 'company_id');

        return parent::getEloquentQuery()->addSelect([
            'company_name' => Company::query()
                ->select('name')
                ->whereColumn('companies.id', 'roles.'.$teamKey)
                ->limit(1),
        ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function table(Table $table): Table
    {
        return PlatformRolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformRoles::route('/'),
        ];
    }
}
