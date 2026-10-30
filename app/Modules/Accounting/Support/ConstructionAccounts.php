<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use RuntimeException;

/**
 * The company's construction account mapping — `docs/construction-management-plan.md` §18.2.
 *
 * Copied in shape from {@see PayrollAccounts}, **including its error messages**, and that is not laziness: "account
 * 5100 cannot accept entries" names neither the caller that chose it nor the fix, and somebody reading it at five
 * o'clock has to go and find both. Every message here names the line that chose the account, the settings page, and
 * the seeder.
 *
 * Here rather than in a construction module for the same reason `PayrollAccounts` is here rather than in Payroll:
 * two places need it and will diverge if each resolves its own. A certificate's retention line credits retention
 * receivable and the release invoice has to debit the same account to clear it; §4's reconciliation and §11's
 * WIP posting read the same map again.
 *
 * **The keys are the semantics, not the codes.** A company that keeps retention in 1625 changes one setting rather
 * than editing a service.
 */
class ConstructionAccounts
{
    /**
     * Every key this class answers to, with what it means, so a caller cannot invent one that silently falls back
     * to nothing.
     *
     * @var array<string, string>
     */
    public const KEYS = [
        'contract_revenue' => 'Certified value of construction work, gross of retention',
        'contract_assets' => 'Work done and not yet certified',
        'contract_liabilities' => 'Advances received and certified value in excess of work done',
        'retention_receivable' => 'Retention held by the employer against us',
        'retention_payable' => 'Retention we hold from subcontractors',
        // The mirror of `contract_liabilities`, which is an advance *received*. An advance paid down to a
        // subcontractor is money out against work not yet done, so it is ours until his certificates recover it.
        'subcontract_advance' => 'Advance paid to a subcontractor, recovered from their certificates',
        'materials_on_site' => 'Delivered and not yet built in',
        'goods_received_not_invoiced' => 'Received against an order with no supplier invoice yet',
        'accrued_subcontract_costs' => 'Subcontract work done and not yet certified',
        'foreseeable_losses' => 'Provision for a contract expected to lose money',
        'job_cost_labour' => 'Job cost, labour',
        'job_cost_material' => 'Job cost, material',
        'job_cost_plant' => 'Job cost, plant',
        'job_cost_subcontract' => 'Job cost, subcontract',
        'job_cost_other' => 'Job cost, everything else',
        'plant_hire_recovery' => 'Internal plant hire charged out to jobs',
        'burden_absorbed' => 'Labour burden charged to jobs at a rate',
        'absorption_variance' => 'Over or under absorption',
    ];

    /**
     * Where the company's choices live: line key => account id, chosen from the chart on Company Settings.
     *
     * Account ids, never codes, and nothing behind them: the shipped defaults used to be account numbers in config
     * that this class fell back to, and one of them (goods received not invoiced at 2600) was another account's
     * number in the standard chart. `ConstructionAccountsSeeder` writes the map when it creates the accounts, and a
     * tenant migration wrote it for every company seeded before.
     */
    public const SETTING = 'construction.accounts';

    /** @return array<string, int> line key => account id, only the lines that have been chosen */
    public static function map(): array
    {
        return array_filter(
            array_map(fn ($id): int => (int) $id, array_intersect_key((array) (setting(self::SETTING) ?: []), self::KEYS)),
            fn (int $id): bool => $id > 0,
        );
    }

    /** The code of the account chosen for a key, for display; posting asks for {@see id()}. */
    public static function code(string $key): string
    {
        return (string) static::account($key)->code;
    }

    /**
     * The account id, refusing anything that cannot take an entry.
     *
     * The refusal is caught here rather than in `JournalEntryService::validateLines()`, which only knows it was
     * handed an unpostable account and reports it as "Line 0: account 5100 cannot accept entries" — true, and it
     * names neither the construction line that chose it nor what to do about it.
     */
    public static function id(string $key): int
    {
        $account = static::account($key);

        if ($reason = $account->entryRefusalReason()) {
            throw new RuntimeException(
                "Construction account '{$key}' points at account {$account->code} ({$account->name}), which cannot "
                ."receive entries because {$reason}. Construction must post to a leaf account: either fix that "
                .'account under Accounting → Chart of Accounts, or choose another under Company Settings → Money → '
                .'Construction accounts.'
            );
        }

        return $account->id;
    }

    private static function account(string $key): Account
    {
        if (! array_key_exists($key, self::KEYS)) {
            throw new RuntimeException(
                "'{$key}' is not a construction account key. The list is in ConstructionAccounts::KEYS."
            );
        }

        $id = static::map()[$key] ?? null;
        $account = $id === null ? null : Account::query()->find($id);

        if (! $account) {
            throw new RuntimeException(
                "No account is chosen for construction line '{$key}' (".self::KEYS[$key].'). Choose one under '
                .'Company Settings → Money → Construction accounts, or seed the construction accounts with '
                .'ConstructionAccountsSeeder, which creates them and chooses them for you.'
            );
        }

        return $account;
    }

    /**
     * The job-cost account for a cost type, so callers pass `labour` rather than composing a key.
     *
     * One place that knows the mapping from §3.2's five cost types to five accounts. A caller building the key
     * itself is a caller that will one day build `job_cost_subcontractor` and get a runtime error on a Friday.
     */
    public static function jobCostIdFor(string $costType): int
    {
        $key = 'job_cost_'.$costType;

        return static::id(array_key_exists($key, self::KEYS) ? $key : 'job_cost_other');
    }

    /**
     * Whether the mapping is usable at all, without throwing.
     *
     * For screens that would rather hide an action than offer one that fails: §18.1's "smaller, never broken".
     */
    public static function isConfigured(): bool
    {
        try {
            foreach (['contract_revenue', 'retention_receivable', 'contract_liabilities'] as $key) {
                static::id($key);
            }

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
