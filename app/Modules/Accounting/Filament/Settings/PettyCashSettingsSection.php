<?php

namespace App\Modules\Accounting\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * The petty cash float — the imprest the box is restored to each month.
 *
 * Accounting's own setting, contributed to Company Settings like its others, rather than a field Core's
 * page carried for it. It was also required there, so a company that keeps no petty cash could not save
 * any setting at all without inventing a float; blank now means "no float", and the Petty Cash Book says
 * so instead of offering a replenishment of nothing.
 *
 * Until a company saves one, the installation default applies (`config/petty_cash.php`, PETTY_CASH_FLOAT).
 */
class PettyCashSettingsSection implements SettingsSection
{
    public const FLOAT = 'petty_cash.float_amount';

    public function key(): string
    {
        return 'accounting.petty-cash';
    }

    public function components(): array
    {
        return [
            Section::make('Petty cash')
                ->description('The cash box the Petty Cash Book keeps.')
                ->schema([
                    TextInput::make('petty_cash_float_amount')
                        ->label('Float Amount')
                        ->numeric()
                        ->minValue(0)
                        ->nullable()
                        ->helperText('The imprest the box is restored to each month: Replenish Month raises a payment to '
                            .'the custodian for the float less what is left in the box. Leave empty if this company '
                            .'keeps no petty cash.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return ['petty_cash_float_amount' => setting(self::FLOAT)];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('petty_cash_float_amount', $state)) {
            return;
        }

        $value = $state['petty_cash_float_amount'];

        // Blank is stored as blank — this company keeps no float — not as nothing, which would hand it back
        // the installation default.
        app(TenantSettings::class)->set(self::FLOAT, $value === null || $value === '' ? null : (float) $value);
    }
}
