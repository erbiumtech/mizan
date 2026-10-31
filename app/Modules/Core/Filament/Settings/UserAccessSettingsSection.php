<?php

namespace App\Modules\Core\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * How an account made on the Users page starts.
 *
 * The `status` column defaults to 0, and the form had no field for it, so every account made there was
 * deactivated: the person could not sign in with the password they had just been given, no leave request
 * reached them as an approver, no announcement reached them. Whether that is the company's intent is the
 * company's call, so it is a setting — active by default, which is what somebody typing a password for a
 * new colleague expects.
 */
class UserAccessSettingsSection implements SettingsSection
{
    public const KEY = 'users.new_active';

    public static function newAccountsActive(): bool
    {
        return (bool) setting(self::KEY, true);
    }

    public function key(): string
    {
        return 'users.access';
    }

    public function components(): array
    {
        return [
            Section::make('Users')
                ->description('Accounts made on the Users page.')
                ->schema([
                    Toggle::make('users_new_active')
                        ->label(__('New accounts can sign in straight away'))
                        ->helperText('On, an account made on the Users page is active and can sign in with the password typed there. '
                            .'Off, it starts deactivated until somebody presses Activate on the Users list. Either way the '
                            .'Active switch on the form decides for that one account. Invitations are not affected: an '
                            .'invited person is active and signs in by setting their own password.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return ['users_new_active' => self::newAccountsActive()];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('users_new_active', $state)) {
            return;
        }

        app(TenantSettings::class)->set(self::KEY, (bool) $state['users_new_active']);
    }
}
