<?php

namespace App\Modules\Advances\Filament\Settings;

use App\Modules\Advances\Models\Advance;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;

/**
 * When an advance starts coming back — B17.
 *
 * An advance with no start month used to recover in any payroll month not yet raised, so one handed over on
 * 30 October was taken from a September payslip raised afterwards. The company says how a blank start is read;
 * a start month set on the advance itself always wins.
 */
class AdvanceRecoverySettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'advances.recovery';
    }

    public function components(): array
    {
        return [
            Section::make('Advances')
                ->description('When an advance with no start month of its own begins to be deducted.')
                ->visible(fn (): bool => modules()->enabled('advances'))
                ->schema([
                    Select::make('advances_recovery_starts')
                        ->label('Recovery starts')
                        ->options([
                            Advance::RECOVERY_FROM_MONTH_GIVEN => 'From the month the advance is given',
                            Advance::RECOVERY_FROM_MONTH_AFTER => 'From the month after it is given',
                            Advance::RECOVERY_FROM_ANY_MONTH => 'From the next payslip raised, whatever its month',
                        ])
                        ->selectablePlaceholder(false)
                        ->helperText('Only for an advance whose "Start deducting from" is blank. The last choice also deducts from a payslip raised later for a month before the advance was given.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'advances_recovery_starts' => setting(Advance::RECOVERY_STARTS_SETTING, Advance::RECOVERY_FROM_MONTH_GIVEN),
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('advances_recovery_starts', $state)) {
            return;
        }

        app(TenantSettings::class)->set(Advance::RECOVERY_STARTS_SETTING, $state['advances_recovery_starts'] ?: Advance::RECOVERY_FROM_MONTH_GIVEN);
    }
}
