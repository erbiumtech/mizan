<?php

namespace App\Modules\Payroll\Filament\Settings;

use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\StatutoryContributions;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

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
                        ->label(__('A run must be approved before it is signed off'))
                        ->helperText('Off: the accountant signs off directly. On: somebody holding Approve payroll runs reads it first.')
                        ->columnSpanFull(),
                    Toggle::make('payroll_pay_by_bank')
                        ->label(__('Salaries are paid by bank'))
                        ->helperText('On: an employee with no account number or IBAN blocks the run. Off for a company that pays cash.')
                        ->columnSpanFull(),
                    Select::make('payroll_rounding')
                        ->label(__('Round net pay'))
                        ->options([0 => 'To the rupee', 10 => 'To the nearest 10', 100 => 'To the nearest 100'])
                        ->helperText('The difference is a rounding line; no component changes.'),
                    TextInput::make('payroll_pay_day')
                        ->label(__('Pay day (day of month)'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(31)
                        ->helperText('Shown on every run. Leave empty if it varies.'),
                    // B13: who can answer an objection. The gate on closing one exists so a salary is not released
                    // over a complaint nobody engaged with.
                    Toggle::make('payroll_objection_employee_reply_counts')
                        ->label(__('An employee\'s own follow-up counts as the reply to their objection'))
                        ->helperText('Off: an objection can be closed only once somebody other than the employee has replied in the payslip\'s comments. On: any later comment opens it, the employee\'s own included.')
                        ->columnSpanFull(),
                ]),

            // The provident fund was a server switch (STATUTORY_PF_ENABLED) — the same for every company on the
            // installation. Each company now decides; an unset value still reads the server's default.
            Section::make('Provident fund')
                ->description('Monthly contributions to the fund, recorded in the provident-fund ledger when a run is signed off.')
                ->visible(fn (): bool => modules()->enabled('payroll'))
                ->columns(2)
                ->schema([
                    Toggle::make('payroll_provident_fund_enabled')
                        ->label(__('This company runs a provident fund'))
                        ->live()
                        ->helperText('On: signing off a run records each employee\'s and the employer\'s contribution in the ledger. Off: nothing is recorded.')
                        ->columnSpanFull(),
                    TextInput::make('payroll_provident_fund_employee_rate')
                        ->label(__('Employee contribution (% of basic wage)'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->visible(fn (Get $get): bool => (bool) $get('payroll_provident_fund_enabled'))
                        ->helperText('Taken from the basic wage each month.'),
                    TextInput::make('payroll_provident_fund_employer_rate')
                        ->label(__('Employer contribution (% of basic wage)'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->visible(fn (Get $get): bool => (bool) $get('payroll_provident_fund_enabled'))
                        ->helperText('The employer\'s match, held for the fund.'),
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
            'payroll_objection_employee_reply_counts' => (bool) setting(Payslip::OBJECTION_EMPLOYEE_REPLY_COUNTS, false),
            'payroll_provident_fund_enabled' => app(StatutoryContributions::class)->providentFundEnabled(),
            // Stored and read as a fraction; shown as a percentage.
            'payroll_provident_fund_employee_rate' => round(app(StatutoryContributions::class)->providentFundRate('employee') * 100, 4),
            'payroll_provident_fund_employer_rate' => round(app(StatutoryContributions::class)->providentFundRate('employer') * 100, 4),
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
        $settings->set(Payslip::OBJECTION_EMPLOYEE_REPLY_COUNTS, (bool) ($state['payroll_objection_employee_reply_counts'] ?? false));

        if (array_key_exists('payroll_provident_fund_enabled', $state)) {
            $settings->set(StatutoryContributions::PROVIDENT_FUND_ENABLED, (bool) $state['payroll_provident_fund_enabled']);

            foreach (['employee', 'employer'] as $side) {
                $value = $state["payroll_provident_fund_{$side}_rate"] ?? null;

                if (filled($value)) {
                    $settings->set("payroll.provident_fund.{$side}_rate", round((float) $value / 100, 6));
                }
            }
        }
    }
}
