<?php

namespace App\Modules\Core\Filament\Platform\Resources\Users;

use App\Modules\Core\Filament\Platform\Resources\Users\Pages\CreatePlatformUser;
use App\Modules\Core\Filament\Platform\Resources\Users\Pages\EditPlatformUser;
use App\Modules\Core\Filament\Platform\Resources\Users\Pages\ListPlatformUsers;
use App\Modules\Core\Filament\Platform\Resources\Users\Schemas\PlatformUserForm;
use App\Modules\Core\Filament\Platform\Resources\Users\Tables\PlatformUsersTable;
use App\Modules\Core\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every account in the installation.
 *
 * A second resource over the same model rather than a widened UserResource, which scopes
 * to the current company twice over — `$isScopedToTenant` on the `companies` pivot, and
 * `getEloquentQuery()` calling `inCurrentCompany()->exceptPlatformAdmins()` so the boundary
 * holds even off a panel request. Both are correct for a company's own list of members,
 * and neither can be switched off by which panel is asking without making that boundary
 * depend on the caller. So this is the unscoped one, and it lives where being unscoped is
 * the point.
 *
 * Platform accounts are included here — they are the accounts this screen exists to
 * manage — and excluded from the company panel's list, which is what
 * `exceptPlatformAdmins()` is for.
 */
class PlatformUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Access Control';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'user';

    protected static ?string $slug = 'users';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function form(Schema $schema): Schema
    {
        return PlatformUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlatformUsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformUsers::route('/'),
            'create' => CreatePlatformUser::route('/create'),
            'edit' => EditPlatformUser::route('/{record}/edit'),
        ];
    }
}
