<?php

namespace App\Modules\Core\Filament\Platform\Resources\Permissions;

use App\Modules\Core\Filament\Platform\Resources\Permissions\Pages\CreatePermission;
use App\Modules\Core\Filament\Platform\Resources\Permissions\Pages\EditPermission;
use App\Modules\Core\Filament\Platform\Resources\Permissions\Pages\ListPermissions;
use App\Modules\Core\Filament\Platform\Resources\Permissions\Schemas\PermissionForm;
use App\Modules\Core\Filament\Platform\Resources\Permissions\Tables\PermissionsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Permission\Models\Permission;
use UnitEnum;

/**
 * The permissions the code checks.
 *
 * Installation-level, which is why this lives on the platform panel: a permission row is
 * meaningful only because some `can('…')` names it, so creating one a company invented
 * does nothing and deleting one breaks every company at once. A company administrator
 * decides which of its *roles* hold which permissions — that resource stays on their
 * panel — and not what the set of permissions is.
 */
class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Access Control';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        // Beside the panel's own front door, because this class is one rename away from
        // being discovered by the company panel again.
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'group'];
    }

    public static function form(Schema $schema): Schema
    {
        return PermissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PermissionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'edit' => EditPermission::route('/{record}/edit'),
        ];
    }
}
