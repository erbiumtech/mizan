<?php

namespace App\Modules\Core\Filament\Pages\Auth;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Support\NotificationPreferences;
use App\Support\TenantDb;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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

                // The language this person reads — docs/saas-launch-plan.md §8.10. Theirs, not the
                // company's: an office can run in English while one person reads Urdu.
                \Filament\Forms\Components\Select::make('locale')
                    ->label('Language')
                    ->options(\App\Support\Locales::options())
                    ->placeholder('Follow the company ('.(\App\Support\Locales::AVAILABLE[\App\Support\Locales::company()]['label'] ?? 'English').')')
                    ->dehydrated(false)
                    ->afterStateHydrated(fn ($component) => $component->state($this->getUser()->preferences['locale'] ?? null))
                    ->live()
                    ->afterStateUpdated(function ($state): void {
                        $user = $this->getUser();
                        $user->preferences = array_merge((array) $user->preferences, ['locale' => \App\Support\Locales::supported($state) ? $state : null]);
                        $user->save();
                        \Filament\Notifications\Notification::make()->success()->title('Language saved. It applies on the next page you open.')->send();
                    }),

                /*
                 * Which emails this person wants — docs/saas-launch-plan.md §14.7.
                 *
                 * Phrased as what to *stop* rather than what to receive, and stored that way too: a
                 * category added next year arrives switched on, which is the right default for somebody
                 * who made this choice a year ago about the categories that existed then.
                 *
                 * Saved on change, like the language above, because the rest of this form is gated on
                 * the current password and a person should not have to type it to stop an email.
                 */
                CheckboxList::make('notifications_off')
                    ->label('Emails to stop sending me')
                    ->options(NotificationPreferences::choosable())
                    ->columns(2)
                    ->dehydrated(false)
                    ->helperText('Anything you stop here still appears in the bell at the top of the panel. '
                        .'Emails about your account and your access cannot be stopped.')
                    ->afterStateHydrated(fn (CheckboxList $component) => $component->state(NotificationPreferences::silenced($this->getUser())))
                    ->live()
                    ->afterStateUpdated(function ($state): void {
                        NotificationPreferences::set($this->getUser(), (array) $state);
                        Notification::make()->success()->title('Saved.')->send();
                    }),

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
