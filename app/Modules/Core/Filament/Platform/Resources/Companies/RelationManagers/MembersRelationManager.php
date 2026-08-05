<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\RelationManagers;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may sign in to this company, and as what.
 *
 * Membership is the `company_user` pivot and the role is per company (spatie teams), so
 * the two questions belong together: attaching somebody without a role gets them a panel
 * with nothing in it, and a role without membership is a grant nobody can use.
 *
 * Every role read or write below is wrapped in the company's team id. On this panel there
 * is no current company, so the registrar's team is null and `assignRole('Administrator')`
 * would look in a team that has no roles — the error would be "role does not exist",
 * which says nothing about the actual problem.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Members';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),

                TextColumn::make('roles')
                    ->label('Roles here')
                    ->badge()
                    ->state(fn (User $record): array => $this->rolesOf($record))
                    ->placeholder('none — cannot use the panel'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn ($state): string => $state ? 'success' : 'gray'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add member')
                    // Any account in the installation, not only this company's: adding
                    // somebody who already works for another company is the ordinary case
                    // for an accountant or a contractor.
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->recordSelectOptionsQuery(fn ($query) => $query->acrossCompanies())
                    ->after(fn () => $this->flushRoleCache()),
            ])
            ->recordActions([
                $this->setRolesAction(),
                DetachAction::make()
                    ->label('Remove')
                    ->before(fn (User $record) => $this->withTeam(fn () => $record->syncRoles([]))),
            ]);
    }

    /**
     * Set what this member is in this company.
     *
     * One action rather than an "appoint administrator" button, because the question a
     * platform admin actually has is "what is this person here", and the answer includes
     * Accountant and CEO. The role list comes from this company's own roles, so it cannot
     * offer another company's.
     */
    protected function setRolesAction(): Action
    {
        return Action::make('setRoles')
            ->label('Set roles')
            ->icon('heroicon-o-shield-check')
            ->color('gray')
            ->schema([
                Select::make('roles')
                    ->label('Roles in this company')
                    ->multiple()
                    ->options(fn (): array => $this->companyRoles())
                    ->helperText('Administrator can do anything within this company. Leave empty to remove every role.'),
            ])
            ->fillForm(fn (User $record): array => ['roles' => $this->rolesOf($record)])
            ->action(function (array $data, User $record): void {
                if ($this->companyRoles() === []) {
                    Notification::make()
                        ->danger()
                        ->title('This company has no roles yet')
                        ->body('Seed them from the Roles tab first — a role cannot be granted before it exists.')
                        ->send();

                    return;
                }

                $this->withTeam(fn () => $record->syncRoles($data['roles'] ?? []));

                $named = $data['roles'] ?? [];

                Notification::make()
                    ->success()
                    ->title($named === []
                        ? "{$record->name} now has no role in {$this->company()->name}"
                        : "{$record->name}: ".implode(', ', $named))
                    ->send();
            });
    }

    /** @return array<int, string> */
    protected function rolesOf(User $user): array
    {
        return $this->withTeam(fn (): array => $user->roles()->pluck('name')->all());
    }

    /** @return array<string, string> */
    protected function companyRoles(): array
    {
        return $this->company()->roles()->orderBy('name')->pluck('name', 'name')->all();
    }

    protected function company(): Company
    {
        return $this->getOwnerRecord();
    }

    /**
     * Run something with this company as spatie's team, and put the previous one back.
     *
     * Restored rather than cleared: this runs inside one request that may be doing
     * something else afterwards, and leaving the registrar pointing at whichever company
     * was last looked at is how a role lands on the wrong team.
     */
    protected function withTeam(callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($this->company()->getKey());

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }
    }

    protected function flushRoleCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        // Belt and braces beside the panel's own front door: this manager reads and
        // writes role grants for a company the viewer may not belong to.
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
