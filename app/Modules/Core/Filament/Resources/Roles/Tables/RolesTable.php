<?php

namespace App\Modules\Core\Filament\Resources\Roles\Tables;

use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use App\Support\SystemRoles;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('guard_name')
                    ->label('Guard')
                    ->sortable(),

                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->badge(),

                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->badge(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                /*
                 * Duplicate — docs/saas-launch-plan.md §14.9.
                 *
                 * The usual way a company gets the role it wants is "Manager, but without
                 * the ledger", and building that from an empty checkbox list means reading
                 * two hundred permissions and hoping. A copy starts from something that
                 * already works.
                 *
                 * The copy is never a system role whatever it was copied from: it carries a
                 * new name, so the seeder will not find it and will not grant to it.
                 */
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('A new role with the same permissions and nobody in it, for you to edit.')
                    ->visible(fn (): bool => auth()->user()?->can('createRole') ?? false)
                    ->action(function (Role $record): void {
                        $companyId = RoleResource::currentCompanyId();

                        $name = SystemRoles::copyNameFor(
                            (string) $record->name,
                            fn (string $candidate): bool => Role::query()
                                ->where('name', $candidate)
                                ->where(config('permission.column_names.team_foreign_key', 'company_id'), $companyId)
                                ->exists(),
                        );

                        $copy = Role::create([
                            'name' => $name,
                            'guard_name' => $record->guard_name,
                            config('permission.column_names.team_foreign_key', 'company_id') => $companyId,
                        ]);

                        $copy->syncPermissions($record->permissions);

                        Notification::make()->success()->title('Copied as "'.$name.'".')->send();
                    }),

                EditAction::make(),

                DeleteAction::make()
                    // A system role deleted is a system role the next deploy recreates, and a
                    // company that renamed one ends up with two. See App\Support\SystemRoles.
                    ->visible(fn (Role $record): bool => ! SystemRoles::isSystem($record->name))
                    ->modalDescription('Everybody holding it loses it. They are not deactivated — they simply hold '
                        .'nothing from this role any more.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        // Same rule, applied to the selection: the built-in roles are skipped
                        // rather than the whole delete being refused.
                        ->action(function (Collection $records): void {
                            $kept = $records->filter(fn (Role $role): bool => SystemRoles::isSystem($role->name));
                            $records->reject(fn (Role $role): bool => SystemRoles::isSystem($role->name))
                                ->each(fn (Role $role) => $role->delete());

                            $kept->isEmpty()
                                ? Notification::make()->success()->title('Deleted.')->send()
                                : Notification::make()->warning()
                                    ->title('Deleted, except the built-in roles')
                                    ->body('Kept: '.$kept->pluck('name')->join(', ').'. The seeder recreates those on every deploy.')
                                    ->send();
                        }),
                ]),
            ]);
    }
}
