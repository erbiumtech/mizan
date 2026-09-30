<?php

namespace App\Modules\Core\Filament\Pages\Auth;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\Company;
use App\Support\TenantDb;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Throwable;

/**
 * Self-service profile — name and password — reachable from the user menu by
 * every signed-in user, employees included.
 *
 * **Name is editable, EXCEPT for a user linked to an employee record.** An
 * employee's name lives on their Employee row, where a self-edit becomes a
 * pending EmployeeChangeRequest for approval; letting this page write the user's
 * name directly would bypass that flow and leave the two names disagreeing. So
 * the field is read-only precisely for those users and open for everyone else —
 * super admins, and standalone accounts with no employee record. The check is on
 * the *record*, not the Employee role: a role without a row has no approval flow
 * to protect. Company email stays read-only for all — it is login identity, and
 * changing it is not what a profile page is for.
 *
 * Password change is unchanged: new password with confirmation, gated on the
 * current one. MFA is separate and still enforced at login.
 */
class EditProfile extends BaseEditProfile
{
    protected static ?string $title = 'My Profile';

    public function form(Schema $schema): Schema
    {
        $nameManagedElsewhere = $this->nameManagedByEmployeeRecord();

        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required(! $nameManagedElsewhere)
                    ->maxLength(255)
                    ->disabled($nameManagedElsewhere)
                    ->dehydrated(! $nameManagedElsewhere)
                    ->helperText($nameManagedElsewhere
                        ? 'Your name comes from your employee record. Change it under Employees — the edit goes for approval.'
                        : null),

                TextInput::make('email')
                    ->label('Company Email')
                    ->disabled()
                    ->dehydrated(false),

                $this->getPasswordFormComponent()
                    ->label('New Password')
                    // Optional now: someone updating only their name should not be
                    // forced to also set a password. Blank leaves the password as is.
                    ->required(false),

                $this->getPasswordConfirmationFormComponent()
                    ->label('Confirm New Password'),

                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    /**
     * Does this user have an employee record whose approval flow owns their name?
     *
     * Queried through TenantDb rather than the Employee model, because Core may
     * not import a module's models. Only meaningful inside a company with the
     * Employees module: on the platform panel there is no tenant and no employee,
     * so the name is the user's own to change.
     */
    private function nameManagedByEmployeeRecord(): bool
    {
        // Filament's tenant first, spatie's current second — the panel sets the
        // former, the multitenancy middleware the latter; same idiom as TaxEstimate.
        $company = \Filament\Facades\Filament::getTenant() ?? Company::current();

        if (! $company || ! modules()->enabled('employees')) {
            return false;
        }

        try {
            return TenantDb::table('employees')->where('user_id', auth()->id())->exists();
        } catch (Throwable) {
            return false;
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Profile updated.';
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('edit-profile', 'My Profile: Help'),
        ];
    }
}
