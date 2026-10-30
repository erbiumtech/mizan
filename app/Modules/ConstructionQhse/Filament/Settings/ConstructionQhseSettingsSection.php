<?php

namespace App\Modules\ConstructionQhse\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;

/**
 * The safety-rate base — `docs/construction-management-plan.md` §17.
 *
 * A frequency rate is incidents per so many hours worked, and which "so many" is a reporting convention rather than a
 * fact: the ILO and most of the world quote per 1,000,000 hours, OSHA per 200,000. The same site reads a factor of
 * five apart on the two, so the company says which one its rates mean, and the report still lets a reader switch.
 */
class ConstructionQhseSettingsSection implements SettingsSection
{
    /** @var array<int, string> */
    public const BASES = [
        1_000_000 => 'Per 1,000,000 hours worked (ILO, UK, most of the world)',
        200_000 => 'Per 200,000 hours worked (OSHA — 100 workers for a year)',
        100_000 => 'Per 100,000 hours worked',
    ];

    public function key(): string
    {
        return 'construction.qhse';
    }

    public function components(): array
    {
        return [
            Section::make('Construction safety')
                ->visible(fn (): bool => modules()->enabled('construction_qhse'))
                ->schema([
                    Select::make('construction_qhse_rate_base')
                        ->label('Safety rates are quoted')
                        ->options(self::BASES)
                        ->selectablePlaceholder(false)
                        ->helperText('The hours every frequency and severity rate is expressed against — lost-time, '
                            .'recordable and accident rates and the severity rate. The safety indicators report opens '
                            .'on this base and prints it beside every figure.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return ['construction_qhse_rate_base' => (int) setting('construction.qhse.rate_base', 1_000_000)];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('construction_qhse_rate_base', $state)) {
            return;
        }

        $base = (int) $state['construction_qhse_rate_base'];

        app(TenantSettings::class)->set('construction.qhse.rate_base', array_key_exists($base, self::BASES) ? $base : 1_000_000);
    }
}
