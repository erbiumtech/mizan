<?php

namespace App\Modules\Core\Filament\Resources\Users\Schemas;

use App\Modules\Core\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(table: User::class, column: 'email', ignoreRecord: true),

                // Nova Password: required + min:8 on create, nullable + min:8 on update.
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create'),

                // Super admin — only a super admin can grant/revoke this.
                Toggle::make('is_super_admin')
                    ->label('Super Admin')
                    ->helperText('Manages all companies and can switch into any tenant.')
                    ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false),

                // Company membership (which companies this user can access).
                //
                // Super admin only: the options are every company in the system,
                // and granting access to one is not a company administrator's
                // call to make. Their own company is attached automatically on
                // create (see UserResource::$isScopedToTenant), which is the only
                // membership this page needs.
                Select::make('companies')
                    ->label('Company Access')
                    ->multiple()
                    ->relationship('companies', 'name')
                    ->preload()
                    ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false)
                    ->helperText('Companies this user can sign in to. Switch companies to set roles for each.'),

                // Roles are per-company (spatie teams); this applies to the
                // company whose panel you are currently in. Not a real column —
                // hydrated/synced by the Create/Edit pages.
                Select::make('roles')
                    ->label('Roles in '.(Filament::getTenant()?->name ?? 'this company'))
                    ->multiple()
                    ->options(fn (): array => Role::query()
                        ->where('company_id', app(PermissionRegistrar::class)->getPermissionsTeamId())
                        ->orderBy('name')
                        ->pluck('name', 'name')
                        ->all())
                    ->dehydrated(false)
                    ->helperText('Applies only to the current company. Switch company to manage roles elsewhere.'),
            ]);
    }
}
