<?php

namespace App\Modules\Accounting\Filament\Settings;

use App\Modules\Accounting\Models\Account;
use App\Support\Contracts\SettingsSection;
use App\Support\KeyValueState;
use App\Support\TenantSettings;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

/**
 * How payroll reaches the ledger: whether its entries post themselves, and which accounts they hit.
 *
 * Lived in `Core\Filament\Pages\CompanySettings`, whose only reference to `Account` was the validation rule
 * below — see docs/module-packaging-plan.md §9.
 *
 * **Accounting's, though Payroll reads it.** Both keys are `accounting.*` and both describe the accounting
 * side of a payroll run: `auto_post_payroll` is read by `Payroll\Services\PayrollAutoPosting` and
 * `payroll_accounts` by `Accounting\Support\PayrollAccounts` and `Payroll\Services\PayrollAccountAudit`. What
 * settles the ownership is the validation — only Accounting can say whether a code exists in the chart — and
 * Payroll requires Accounting, so reading a key Accounting defines costs nothing.
 */
class PayrollPostingSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'accounting.payroll-posting';
    }

    public function components(): array
    {
        return [
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
                        ->editableKeys(false)
                        ->helperText('Each line must name a code that exists in the chart of accounts. Leave one blank to fall back to the shipped default.')
                        // Saving a code that does not exist breaks payroll
                        // posting at the point of use, long after the save —
                        // and these keys are not addable, so the mistake
                        // cannot be undone from this page. Catch it here.
                        ->rule(static function (): callable {
                            return static function (string $attribute, $value, callable $fail): void {
                                // Only scalar entries are account codes; a blank
                                // one means "fall back to the default".
                                $codes = collect(KeyValueState::map($value))
                                    ->filter(fn ($code) => is_scalar($code) && trim((string) $code) !== '')
                                    ->map(fn ($code) => trim((string) $code));

                                if ($codes->isEmpty()) {
                                    return;
                                }

                                // A company whose chart has not been seeded yet
                                // would fail on every shipped default, locking
                                // the admin out of the whole settings page over
                                // values they never typed.
                                if (Account::query()->doesntExist()) {
                                    return;
                                }

                                $existing = Account::whereIn('code', $codes->values()->all())
                                    ->pluck('code')
                                    ->all();

                                foreach ($codes as $line => $code) {
                                    if (! in_array($code, $existing, true)) {
                                        $fail("Account code {$code} for \"{$line}\" does not exist in the chart of accounts.");
                                    }
                                }
                            };
                        }),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'accounting_auto_post_payroll' => (bool) setting('accounting.auto_post_payroll'),
            'accounting_payroll_accounts' => setting('accounting.payroll_accounts'),
        ];
    }

    public function save(array $state): void
    {
        $settings = app(TenantSettings::class);

        $settings->set('accounting.auto_post_payroll', (bool) ($state['accounting_auto_post_payroll'] ?? false));
        $settings->set('accounting.payroll_accounts', $state['accounting_payroll_accounts'] ?? []);
    }
}
