<?php

namespace App\Modules\ConstructionContracts\Filament\Settings;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\ConstructionAccounts;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;

/**
 * Which accounts construction posts to — `docs/construction-management-plan.md` §18.2.
 *
 * **Here rather than in Core**, and §18.2 names the refusal that puts it here: there is deliberately no
 * `'core' => [… 'construction']` coupling, "because the account map belongs on a Construction settings page and
 * `core -> accounting` already exists for having made that mistake once". The `SettingsSections` registry is what
 * makes that possible — Core keeps the page and the module that owns the setting brings the section.
 *
 * **Each line is chosen from the chart**, by account rather than by a typed code, and there are no shipped account
 * numbers behind it: those used to live in config, and goods received not invoiced defaulted to 2600, which the
 * standard chart gives to Customer Advances. `ConstructionAccountsSeeder` creates the accounts and chooses them; this
 * page is where a company points a line somewhere else.
 *
 * **The one line worth reading twice on this form is retention receivable.** It must point at an *asset*. Retention
 * is money earned and contractually owed, merely not yet payable; netting it off revenue understates turnover for
 * the life of the job and then makes the release look like revenue earned in a period when no work happened, which
 * is what §10.4 exists to prevent.
 */
class ConstructionAccountsSettingsSection implements SettingsSection
{
    private const FIELD = 'construction_account_';

    /** @var array<int, string>|null */
    private ?array $options = null;

    public function key(): string
    {
        return 'construction.accounts';
    }

    public function components(): array
    {
        $fields = [];

        foreach (ConstructionAccounts::KEYS as $key => $meaning) {
            $fields[] = Select::make(self::FIELD.$key)
                ->label(ucfirst($meaning))
                ->options(fn (): array => $this->accountOptions())
                ->searchable()
                ->placeholder('Not chosen');
        }

        return [
            Section::make('Construction accounts')
                ->description('The account each construction line posts to, chosen from your chart. Seeding the '
                    .'construction accounts creates and chooses them; change a line here to point it elsewhere. A line '
                    .'left unchosen refuses to post and says which line it is. Retention receivable must be an asset: '
                    .'a certificate invoices work gross and shows retention as its own line.')
                // Absent for a company that has not bought construction, which is most of them.
                ->visible(fn (): bool => modules()->enabled('construction_contracts') || modules()->enabled('construction_costing'))
                ->collapsible()
                ->columns(2)
                ->schema($fields),
        ];
    }

    public function fill(): array
    {
        $map = ConstructionAccounts::map();
        $state = [];

        foreach (array_keys(ConstructionAccounts::KEYS) as $key) {
            $state[self::FIELD.$key] = $map[$key] ?? null;
        }

        return $state;
    }

    public function save(array $state): void
    {
        $first = self::FIELD.array_key_first(ConstructionAccounts::KEYS);

        // The section was not on the page (no construction module): leave the saved map alone.
        if (! array_key_exists($first, $state)) {
            return;
        }

        $map = [];

        foreach (array_keys(ConstructionAccounts::KEYS) as $key) {
            $id = (int) ($state[self::FIELD.$key] ?? 0);

            if ($id > 0 && Account::query()->whereKey($id)->exists()) {
                $map[$key] = $id;
            }
        }

        app(TenantSettings::class)->set(ConstructionAccounts::SETTING, $map);
    }

    /** @return array<int, string> */
    private function accountOptions(): array
    {
        return $this->options ??= Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Account $account): array => [$account->getKey() => "{$account->code} — {$account->name}"])
            ->all();
    }
}
