<?php

namespace App\Modules\Core\Filament\Resources\Users;

use App\Modules\Core\Filament\Resources\Users\Pages\CreateUser;
use App\Modules\Core\Filament\Resources\Users\Pages\EditUser;
use App\Modules\Core\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\Core\Filament\Resources\Users\Schemas\UserForm;
use App\Modules\Core\Filament\Resources\Users\Tables\UsersTable;
use App\Modules\Core\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    /**
     * Row-level tenant scoping back on for this resource alone. Users are the
     * exception because `users` lives in the landlord database and is shared
     * between companies; see AppServiceProvider for why it is off everywhere
     * else, where one database per company already isolates the rows. Without a
     * boundary drawn by hand here, every company's administrator listed,
     * searched, filtered and could open every user account in the system.
     *
     * Membership is the `company_user` pivot rather than a foreign key, so
     * ownership points at the `companies` relation. That is also what earns the
     * property its keep next to getEloquentQuery() below: on a BelongsToMany
     * Filament attaches a newly created user to the current company on the way
     * out (syncWithoutDetaching, so a membership elsewhere survives), which is
     * what stops this page's Create action producing a user it cannot see.
     */
    protected static bool $isScopedToTenant = true;

    protected static ?string $tenantOwnershipRelationshipName = 'companies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'User';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'name', 'email'];
    }

    /**
     * The same boundary again, stated on the query rather than left to the global
     * scope $isScopedToTenant registers — that one is installed by Filament's
     * SetUpPanel middleware, so it covers a panel request and nothing else. This
     * holds the line wherever the resource query is reached without it: a queue
     * worker, a console command, a test mounting a page component directly.
     *
     * Cross-company user management belongs to the Companies resource; this page
     * is the current company's own list.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->inCurrentCompany()
            ->exceptPlatformAdmins();
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
