<?php

namespace App\Modules\Core\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Self-service password change, reachable from the user menu ("Change
 * Password") by every signed-in user, employees included.
 *
 * Name and company email are deliberately read-only here: for employees they
 * live on the linked user but are only editable through the Employee record,
 * where self-edits become a pending EmployeeChangeRequest. Letting the profile
 * page write them would bypass that approval flow.
 */
class EditProfile extends BaseEditProfile
{
    protected static ?string $title = 'Change Password';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('email')
                    ->label('Company Email')
                    ->disabled()
                    ->dehydrated(false),

                $this->getPasswordFormComponent()
                    ->label('New Password')
                    ->required(),

                $this->getPasswordConfirmationFormComponent()
                    ->label('Confirm New Password'),

                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Password changed.';
    }
}
