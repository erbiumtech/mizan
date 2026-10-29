<?php

namespace App\Modules\Payroll\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/** How a run is signed off and paid — docs/saas-launch-plan.md §14.1. Every switch ships off. */
class PayrollRunSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'payroll.run';
    }

    public function components(): array
    {
        return [
            Section::make('Payroll run')
                ->description('Who has to read a month before it is frozen, how net pay is rounded, and when it is paid.')
                ->visible(fn (): bool => modules()->enabled('payroll'))
                ->columns(2)
                ->schema([
                    Toggle::make('payroll_require_approval')
                        ->label('A run must be approved before it is signed off')
                        ->helperText('Off: the accountant signs off directly. On: somebody holding Approve payroll runs reads it first.')
                        ->columnSpanFull(),
                    Toggle::make('payroll_pay_by_bank')
                        ->label('Salaries are paid by bank')
                        ->helperText('On: an employee with no account number or IBAN blocks the run. Off for a company that pays cash.')
                        ->columnSpanFull(),
                    Select::make('payroll_rounding')
                        ->label('Round net pay')
                        ->options([0 => 'To the rupee', 10 => 'To the nearest 10', 100 => 'To the nearest 100'])
                        ->helperText('The difference is a rounding line; no component changes.'),
                    TextInput::make('payroll_pay_day')
                        ->label('Pay day (day of month)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(31)
                        ->helperText('Shown on every run. Leave empty if it varies.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'payroll_require_approval' => (bool) setting('payroll.require_approval', config('payroll.require_approval', false)),
            'payroll_pay_by_bank' => (bool) setting('payroll.pay_by_bank', config('payroll.pay_by_bank', true)),
            'payroll_rounding' => (int) setting('payroll.rounding', config('payroll.rounding', 0)),
            'payroll_pay_day' => setting('payroll.pay_day', config('payroll.pay_day')) ?: null,
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('payroll_require_approval', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);
        $settings->set('payroll.require_approval', (bool) ($state['payroll_require_approval'] ?? false));
        $settings->set('payroll.pay_by_bank', (bool) ($state['payroll_pay_by_bank'] ?? true));
        $settings->set('payroll.rounding', (int) ($state['payroll_rounding'] ?? 0));
        $settings->set('payroll.pay_day', filled($state['payroll_pay_day'] ?? null) ? (int) $state['payroll_pay_day'] : null);
    }
}
