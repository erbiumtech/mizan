<?php

namespace App\Filament\Pages;

use App\Support\TenantSettings;
use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Per-company (per-tenant) settings editor. Loads the effective values via the
 * TenantSettings accessor (tenant override or config default) and persists
 * overrides into the current tenant's `settings` table.
 */
class CompanySettings extends Page
{
    protected string $view = 'filament.pages.company-settings';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $title = 'Company Settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Administrator') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'petty_cash_float_amount' => setting('petty_cash.float_amount'),
            'accounting_auto_post_payroll' => (bool) setting('accounting.auto_post_payroll'),
            'accounting_payroll_accounts' => setting('accounting.payroll_accounts'),
            'ipayments' => setting('ipayments'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Petty Cash')
                    ->schema([
                        TextInput::make('petty_cash_float_amount')
                            ->label('Float Amount')
                            ->numeric()
                            ->required()
                            ->helperText('The imprest the petty cash box is restored to each month.'),
                    ]),

                Section::make('Payroll')
                    ->schema([
                        Toggle::make('accounting_auto_post_payroll')
                            ->label('Auto-post payroll journal entries')
                            ->helperText('When on, payroll entries are approved and posted on creation; otherwise they await Manager/CEO approval.'),

                        KeyValue::make('accounting_payroll_accounts')
                            ->label('Payroll Account Codes')
                            ->keyLabel('Line')
                            ->valueLabel('Account code')
                            ->addable(false)
                            ->deletable(false)
                            ->editableKeys(false),
                    ]),

                Section::make('iPayments (Salary Bank File)')
                    ->schema([
                        KeyValue::make('ipayments')
                            ->label('iPayments Defaults')
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            ->addable(false)
                            ->deletable(false)
                            ->editableKeys(false),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = app(TenantSettings::class);
        $settings->set('petty_cash.float_amount', (float) $state['petty_cash_float_amount']);
        $settings->set('accounting.auto_post_payroll', (bool) $state['accounting_auto_post_payroll']);
        $settings->set('accounting.payroll_accounts', $state['accounting_payroll_accounts']);
        $settings->set('ipayments', $state['ipayments']);

        Notification::make()->title('Settings saved.')->success()->send();
    }
}
