<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\Tables;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->toggleable(),
                TextColumn::make('users_count')->label('Members')->counts('users')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                self::assignAdminAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Attach a user to the company and grant them the Administrator role in that
     * company's team.
     */
    protected static function assignAdminAction(): Action
    {
        return Action::make('assignAdmin')
            ->label('Assign Admin')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->schema([
                Select::make('user_id')
                    ->label('User')
                    // Any user, not just the current company's — see User::scopeAcrossCompanies().
                    ->options(fn () => User::acrossCompanies()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, Company $record): void {
                $user = User::acrossCompanies()->find($data['user_id']);
                if (! $user) {
                    return;
                }

                if (! $record->users()->whereKey($user->getKey())->exists()) {
                    $record->users()->attach($user->getKey());
                }

                $registrar = app(PermissionRegistrar::class);
                $previous = $registrar->getPermissionsTeamId();
                $registrar->setPermissionsTeamId($record->getKey());

                try {
                    // Ensure the Administrator role exists for this company's team.
                    if (! Role::where('name', 'Administrator')->where('company_id', $record->getKey())->exists()) {
                        Notification::make()
                            ->title('Company roles are missing — seed them first (tenants:artisan db:seed RoleSeeder).')
                            ->danger()->send();

                        return;
                    }

                    $user->assignRole('Administrator');
                } finally {
                    $registrar->setPermissionsTeamId($previous);
                }

                Notification::make()->title("{$user->name} is now an Administrator of {$record->name}.")->success()->send();
            });
    }
}
