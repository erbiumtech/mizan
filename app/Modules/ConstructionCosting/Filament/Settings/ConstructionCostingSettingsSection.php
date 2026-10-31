<?php

namespace App\Modules\ConstructionCosting\Filament\Settings;

use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Support\MatchTolerances;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * Job costing's own policies — the treatment a hand-typed cost starts with, and the three-way match tolerances.
 *
 * Both were config a company could not reach: the match tolerances had a setting key and no screen, and the manual
 * entry's treatment was a constant on the form. `MatchTolerances` already resolved the setting before config, so the
 * tolerances only needed somewhere to be typed.
 */
class ConstructionCostingSettingsSection implements SettingsSection
{
    /** The treatments a company may start a manual entry with. `posted` is the system's to set, never a default. */
    public const MANUAL_TREATMENTS = [
        CostEntry::GL_PENDING => 'Pending — posts to the ledger with the period, under the rule chosen on the entry',
        CostEntry::GL_MIRRORED => 'Mirrored — the ledger already has it (a bill booked in Accounting)',
        CostEntry::GL_MEMO => 'Memo — never reaches the ledger',
    ];

    public function key(): string
    {
        return 'construction.costing';
    }

    public function components(): array
    {
        return [
            Section::make('Construction costing')
                ->visible(fn (): bool => modules()->enabled('construction_costing'))
                ->columns(2)
                ->schema([
                    Select::make('construction_costing_manual_gl_treatment')
                        ->label(__('A cost typed on the Cost entries screen starts as'))
                        ->options(self::MANUAL_TREATMENTS)
                        ->selectablePlaceholder(false)
                        ->columnSpanFull()
                        ->helperText('Pending is the one that reaches the general ledger: the month\'s posting debits the '
                            .'job-cost account and credits the posting rule chosen on the entry. Choose Mirrored if your '
                            .'manual costs are always copies of bills already booked in Accounting. The person typing '
                            .'can still change it on the entry.'),

                    TextInput::make('construction_match_quantity_percent')
                        ->label(__('Three-way match: quantity tolerance (%)'))
                        ->numeric()
                        ->minValue(0)
                        ->helperText('How far what was billed may differ from what arrived before the line needs '
                            .'somebody to accept it.'),

                    TextInput::make('construction_match_price_percent')
                        ->label(__('Three-way match: price tolerance (%)'))
                        ->numeric()
                        ->minValue(0)
                        ->helperText('How far an invoice may differ from the order rate for what it covers.'),

                    TextInput::make('construction_match_minimum_amount')
                        ->label(__('Three-way match: ignore differences under'))
                        ->numeric()
                        ->minValue(0)
                        ->helperText('An absolute floor in your own currency: a smaller difference is never flagged, '
                            .'whatever the percentages say. Zero shows everything.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'construction_costing_manual_gl_treatment' => (string) setting('construction.costing.manual_gl_treatment', CostEntry::GL_PENDING),
            'construction_match_quantity_percent' => MatchTolerances::quantityPercent(),
            'construction_match_price_percent' => MatchTolerances::pricePercent(),
            'construction_match_minimum_amount' => MatchTolerances::minimumAmount(),
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('construction_costing_manual_gl_treatment', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);

        $treatment = (string) $state['construction_costing_manual_gl_treatment'];
        $settings->set('construction.costing.manual_gl_treatment', array_key_exists($treatment, self::MANUAL_TREATMENTS)
            ? $treatment
            : CostEntry::GL_PENDING);

        // Blank stays blank, which `MatchTolerances` reads as "the shipped default"; a typed zero is kept.
        $settings->set('construction.match', [
            'quantity_percent' => $state['construction_match_quantity_percent'] ?? '',
            'price_percent' => $state['construction_match_price_percent'] ?? '',
            'minimum_amount' => $state['construction_match_minimum_amount'] ?? '',
        ]);
    }
}
