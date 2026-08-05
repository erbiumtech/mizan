<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\RelationManagers;

use App\Modules\Core\Models\Company;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\PermissionRegistrar;

/**
 * This company's roles.
 *
 * Shown under the company rather than as a flat list, which is not a presentation
 * preference: five role names across N companies listed together read as duplicates of
 * each other, and that is exactly what somebody reported after provisioning a second
 * company. Under its owner the ambiguity cannot arise.
 *
 * Editing a role's permissions stays on the company panel, where the person doing it can
 * see what those permissions govern. What belongs here is the platform-level question:
 * does this company have its roles at all?
 */
class RolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roles';

    protected static ?string $title = 'Roles';

    protected static ?string $recordTitleAttribute = 'name';

    /** What RoleSeeder creates for a company. */
    private const EXPECTED = ['Administrator', 'Employee', 'Accountant', 'Manager', 'CEO'];

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->badge(),

                TextColumn::make('users_count')
                    ->label('Users here')
                    ->counts('users')
                    ->badge()
                    ->color('gray'),
            ])
            ->headerActions([
                $this->seedRolesAction(),
            ])
            ->emptyStateHeading('This company has no roles')
            ->emptyStateDescription(
                'Nobody can be given anything to do here until it does. Provisioning creates them; '
                .'a company migrated in by hand, or one whose seeders have not been re-run, may not have them.'
            );
    }

    /**
     * Create this company's roles, or bring them up to date with the code.
     *
     * Replaces an instruction to go and run `tenants:artisan db:seed --class=RoleSeeder`,
     * which is what the old assign-admin action told you when it found no Administrator to
     * grant. A platform admin looking at the company that is missing its roles is exactly
     * the person who should be able to fix it, and the seeder is idempotent — re-running
     * it is also how a company picks up a permission added since it was created.
     */
    protected function seedRolesAction(): Action
    {
        return Action::make('seedRoles')
            ->label(fn (): string => $this->isMissingRoles() ? 'Create the standard roles' : 'Re-sync roles')
            ->icon('heroicon-o-shield-check')
            ->color(fn (): string => $this->isMissingRoles() ? 'primary' : 'gray')
            ->requiresConfirmation()
            ->modalDescription(
                'Creates any of the five standard roles this company is missing and brings each '
                ."one's permissions up to date with the code. Existing roles are not duplicated, "
                .'and no membership changes.'
            )
            ->action(function (): void {
                $registrar = app(PermissionRegistrar::class);
                $previous = $registrar->getPermissionsTeamId();

                // The seeder reads the company from the registrar. Named explicitly here
                // because on this panel there is no current company, and with a null team
                // it used to create a set of roles belonging to nobody.
                $registrar->setPermissionsTeamId($this->company()->getKey());

                try {
                    (new RoleSeeder)->run();
                } finally {
                    $registrar->setPermissionsTeamId($previous);
                    $registrar->forgetCachedPermissions();
                }

                Notification::make()
                    ->success()
                    ->title("{$this->company()->name} has its roles")
                    ->body(implode(', ', $this->company()->roles()->orderBy('name')->pluck('name')->all()))
                    ->send();
            });
    }

    protected function isMissingRoles(): bool
    {
        $present = $this->company()->roles()->pluck('name')->all();

        return array_diff(self::EXPECTED, $present) !== [];
    }

    protected function company(): Company
    {
        return $this->getOwnerRecord();
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
