<?php

namespace App\Modules\Core\Filament\Platform\Resources\Users\Schemas;

use App\Modules\Core\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * An account, and which companies it can sign in to.
 *
 * No roles field, deliberately. A role is per company, so "this user's roles" is a
 * question with one answer per company and no answer at all here — it is asked on the
 * company's own Members tab, where the roles offered are that company's.
 */
class PlatformUserForm
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

                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Leave blank to keep the current password.'
                        : null),

                Toggle::make('is_super_admin')
                    ->label('Platform admin')
                    ->helperText('Administers the installation: every company, and this panel.'),

                Toggle::make('status')
                    ->label('Active')
                    ->default(true)
                    ->helperText('An inactive account cannot sign in to any panel.'),

                Select::make('companies')
                    ->label('Company access')
                    ->multiple()
                    ->relationship('companies', 'name')
                    ->preload()
                    ->helperText('Which companies this account may sign in to. What they may do inside one is '
                        .'set by the roles on that company, under Companies → Members.'),
            ]);
    }
}
