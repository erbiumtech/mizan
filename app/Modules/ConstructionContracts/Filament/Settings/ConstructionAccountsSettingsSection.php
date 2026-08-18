<?php

namespace App\Modules\ConstructionContracts\Filament\Settings;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\ConstructionAccounts;
use App\Support\Contracts\SettingsSection;
use App\Support\KeyValueState;
use App\Support\TenantSettings;
use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Components\Section;

/**
 * Which accounts construction posts to — `docs/construction-management-plan.md` §18.2.
 *
 * **Here rather than in Core**, and §18.2 names the refusal that puts it here: there is deliberately no
 * `'core' => [… 'construction']` coupling, "because the account map belongs on a Construction settings page and
 * `core -> accounting` already exists for having made that mistake once". The `SettingsSection` registry is what
 * makes that possible — Core keeps the page and the module that owns the setting brings the section.
 *
 * The shape is `PayrollPostingSettingsSection`'s, including its validation and the reason for it: saving a code that
 * does not exist breaks a certificate at the point of use, long after the save, and these keys are not addable so
 * the mistake cannot be undone from this page.
 *
 * **The one line worth reading twice on this form is retention receivable.** It must point at an *asset*. Retention
 * is money earned and contractually owed, merely not yet payable; netting it off revenue understates turnover for
 * the life of the job and then makes the release look like revenue earned in a period when no work happened, which
 * is what §10.4 exists to prevent.
 */
class ConstructionAccountsSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'construction.accounts';
    }

    public function components(): array
    {
        return [
            Section::make('Construction')
                /*
                 * Absent for a company that has not bought the module, which is most of them. Without this the
                 * block appears on every dental practice's settings page — and, worse, its validation ran against
                 * a chart with no construction accounts in it and locked the admin out of the whole page over
                 * values they never typed. §18.1's "smaller, never broken", on a settings form.
                 */
                ->visible(fn (): bool => modules()->enabled('construction_contracts'))
                ->schema([
                    KeyValue::make('accounting_construction_accounts')
                        ->label('Construction Account Codes')
                        ->keyLabel('Line')
                        ->valueLabel('Account code')
                        ->addable(false)
                        ->deletable(false)
                        ->editableKeys(false)
                        ->helperText(
                            'Each line must name a code that exists in the chart of accounts. Leave one blank to '
                            .'fall back to the shipped default. Retention receivable must be an asset: a '
                            .'certificate invoices work gross and shows retention as its own line, because '
                            .'invoicing net understates revenue for the whole life of the job.'
                        )
                        ->rule(static function (): callable {
                            return static function (string $attribute, $value, callable $fail): void {
                                $codes = collect(KeyValueState::map($value))
                                    ->filter(fn ($code) => is_scalar($code) && trim((string) $code) !== '')
                                    ->map(fn ($code) => trim((string) $code));

                                if ($codes->isEmpty()) {
                                    return;
                                }

                                // A company whose chart has not been seeded yet would fail on every shipped
                                // default, locking the admin out of the whole settings page over values they
                                // never typed.
                                if (Account::query()->doesntExist()) {
                                    return;
                                }

                                /*
                                 * **A code left at its shipped default is not validated here**, and that is the
                                 * difference between this section and the payroll one: the payroll defaults are
                                 * seeded into every chart, while the construction accounts arrive with the
                                 * construction profile. A company that licensed the module and has not run
                                 * `ConstructionAccountsSeeder` yet would otherwise be unable to save this page at
                                 * all — and the refusal it needs belongs at the point of use, where
                                 * `ConstructionAccounts::id()` names the seeder and the missing code.
                                 *
                                 * What this rule is for is a **typo in a code somebody chose**, which is the
                                 * mistake that cannot be undone from a form whose keys are not addable.
                                 */
                                $codes = $codes->reject(
                                    fn (string $code, string $line): bool => $code === (string) config('accounting.construction_accounts.'.$line),
                                );

                                if ($codes->isEmpty()) {
                                    return;
                                }

                                $existing = Account::query()
                                    ->whereIn('code', $codes->values()->all())
                                    ->pluck('code')
                                    ->all();

                                foreach ($codes as $line => $code) {
                                    if (! in_array($code, $existing, true)) {
                                        $fail("Account code {$code} for \"{$line}\" does not exist in the chart of accounts. Seed the construction accounts with ConstructionAccountsSeeder, or point this line at a code you already have.");
                                    }
                                }
                            };
                        }),
                ]),
        ];
    }

    /**
     * The saved map, with every key this module answers to present.
     *
     * Merged over the shipped defaults rather than shown as saved: the keys are not addable on this form, so a
     * company that saved the map before a new key existed would otherwise have no way to set it.
     */
    public function fill(): array
    {
        $saved = setting('accounting.construction_accounts') ?: [];

        $map = [];

        foreach (array_keys(ConstructionAccounts::KEYS) as $key) {
            $map[$key] = $saved[$key] ?? config('accounting.construction_accounts.'.$key);
        }

        return ['accounting_construction_accounts' => $map];
    }

    public function save(array $state): void
    {
        app(TenantSettings::class)->set(
            'accounting.construction_accounts',
            $state['accounting_construction_accounts'] ?? [],
        );
    }
}
