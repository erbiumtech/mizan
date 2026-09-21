<?php

namespace App\Modules\Invoicing\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * The company-wide half of credit control — `docs/erpnext-gap-plan.md` §4 item 5.
 *
 * The limit itself is per customer, on the contact. What is company-wide is the overdue rule: how late a
 * customer's oldest unpaid invoice may be before new billing to them stops. Zero switches it off, which is
 * where every existing company starts.
 */
class CreditControlSettingsSection implements SettingsSection
{
    public const OVERDUE_DAYS = 'invoicing.credit_block_overdue_days';

    public function key(): string
    {
        return 'invoicing.credit-control';
    }

    public function components(): array
    {
        return [
            Section::make('Credit control')
                ->description('Stop issuing new invoices to a customer who is already too far behind. The credit limit '
                    .'itself is set per customer, on their contact record.')
                ->schema([
                    TextInput::make(self::OVERDUE_DAYS)
                        ->label('Block new invoices when anything is overdue by')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(365)
                        ->suffix('days')
                        // Not required: blank and 0 both mean "off", and a required field here made the whole
                        // settings page unsaveable for a company that never touched this section.
                        ->helperText('0 or blank switches this off. Somebody who may override credit limits can still issue.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return [self::OVERDUE_DAYS => (int) setting(self::OVERDUE_DAYS, 0)];
    }

    public function save(array $state): void
    {
        app(TenantSettings::class)->set(self::OVERDUE_DAYS, max(0, (int) ($state[self::OVERDUE_DAYS] ?? 0)));
    }
}
