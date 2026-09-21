<?php

namespace App\Modules\Invoicing\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * The switch for monthly customer statements — `docs/erpnext-gap-plan.md` §4 item 1.
 *
 * One toggle. The period is always the previous calendar month, the recipients are always the customers with
 * a movement or a balance, and the wording is an `EmailTemplate` (`customer_statement`). A statement for any
 * other period is downloaded from the customer's row, on demand.
 */
class StatementSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'invoicing.statements';
    }

    public function components(): array
    {
        return [
            Section::make('Customer statements')
                ->description('Email every customer with activity their statement of account for the previous month, '
                    .'on the 2nd. Nothing is sent while this is off; any customer\'s statement can still be downloaded '
                    .'from their row.')
                ->schema([
                    Toggle::make('invoicing.statements_enabled')
                        ->label('Send monthly statements')
                        ->helperText('Run php artisan invoicing:send-statements --dry-run first to see who would receive one.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return ['invoicing.statements_enabled' => (bool) setting('invoicing.statements_enabled', false)];
    }

    public function save(array $state): void
    {
        app(TenantSettings::class)->set('invoicing.statements_enabled', (bool) ($state['invoicing.statements_enabled'] ?? false));
    }
}
