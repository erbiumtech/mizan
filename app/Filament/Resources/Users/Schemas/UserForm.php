<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

                // Company membership (which companies this user can access).
                Select::make('companies')
                    ->label('Company Access')
                    ->multiple()
                    ->relationship('companies', 'name')
                    ->preload()
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
