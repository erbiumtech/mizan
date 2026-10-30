<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\ConstructionAccounts;
use App\Support\TenantSettings;
use Illuminate\Database\Seeder;

/**
 * The construction accounts — `docs/construction-management-plan.md` §18.2.
 *
 * **A separate seeder rather than an extension of `ChartOfAccountsSeeder`**, and §18.2 gives the reason: "adding a
 * dozen construction accounts to every bookkeeping company's chart is noise, and the profile mechanism exists for
 * exactly this". A dental practice does not need a retention payable account.
 *
 * Two groups of accounts here are worth reading twice.
 *
 * **Retention receivable is an asset, and that is the whole of §10.4.** A certificate invoices the work *gross*
 * and shows retention as its own line against this account. Invoicing net understates revenue and turnover by up to
 * a tenth for the life of the job, and then makes the release invoice look like revenue recognised in a period when
 * no work happened — "precisely the misstatement an audit looks for".
 *
 * **The three recovery accounts are credit-normal expenses**, which looks odd in a chart and is right: an internal
 * plant hire charged to a job is a cost on that job and a *recovery* against the plant department's own cost, so it
 * credits an expense account rather than crediting income. Over- or under-absorption is what is left when the two
 * do not meet, and §4 reports that gap in words rather than balancing it away.
 */
class ConstructionAccountsSeeder extends Seeder
{
    /**
     * Keyed by the group header each account hangs under, so this seeder never invents a top-level group.
     *
     * `key` is the line in {@see ConstructionAccounts::KEYS} the account is chosen for; `code` is only where a new
     * one is created. Goods received not invoiced asks for 2605 because the standard chart's 2600 is Customer
     * Advances — and whatever it asks for, a code that already belongs to another account is never borrowed.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private const ACCOUNTS = [
        '1000' => [
            ['key' => 'materials_on_site', 'code' => '1600', 'name' => 'Materials on Site', 'type' => 'asset', 'description' => 'Delivered to site and not yet built in; claimable where the contract allows it'],
            ['key' => 'contract_assets', 'code' => '1610', 'name' => 'Contract Assets', 'type' => 'asset', 'description' => 'Work done and not yet certified — the uncertified half of work in progress'],
            ['key' => 'retention_receivable', 'code' => '1620', 'name' => 'Retention Receivable', 'type' => 'asset', 'description' => 'Retention held by the employer: earned, contractually owed, not yet payable'],
            ['key' => 'subcontract_advance', 'code' => '1630', 'name' => 'Subcontract Advances', 'type' => 'asset', 'description' => 'Advance paid down to a subcontractor, recovered from their certificates. The mirror of 2630, which is an advance received'],
        ],
        '2000' => [
            ['key' => 'goods_received_not_invoiced', 'code' => '2605', 'name' => 'Goods Received Not Invoiced', 'type' => 'liability', 'description' => 'Received on site against a purchase order with no supplier invoice yet'],
            ['key' => 'accrued_subcontract_costs', 'code' => '2610', 'name' => 'Accrued Subcontract Costs', 'type' => 'liability', 'description' => 'Subcontract work done and not yet certified or invoiced'],
            ['key' => 'retention_payable', 'code' => '2620', 'name' => 'Retention Payable', 'type' => 'liability', 'description' => 'Retention held from subcontractors, owed back to them'],
            ['key' => 'contract_liabilities', 'code' => '2630', 'name' => 'Contract Liabilities', 'type' => 'liability', 'description' => 'Advance payments received and certified value in excess of work done'],
            ['key' => 'foreseeable_losses', 'code' => '2640', 'name' => 'Provision for Foreseeable Losses', 'type' => 'liability', 'description' => 'A loss-making contract is provided for in full as soon as it is foreseen (§4.4)'],
        ],
        '4000' => [
            ['key' => 'contract_revenue', 'code' => '4600', 'name' => 'Contract Revenue', 'type' => 'income', 'description' => 'Certified value of construction work, gross of retention'],
        ],
        '5000' => [
            // Job cost by type, one account each: the labour/material/plant/subcontract split is the first cut
            // anybody asks for, and a single job-cost account cannot answer it (§3.2).
            ['key' => 'job_cost_labour', 'code' => '5620', 'name' => 'Job Cost — Labour', 'type' => 'expense'],
            ['key' => 'job_cost_material', 'code' => '5630', 'name' => 'Job Cost — Material', 'type' => 'expense'],
            ['key' => 'job_cost_plant', 'code' => '5640', 'name' => 'Job Cost — Plant', 'type' => 'expense'],
            ['key' => 'job_cost_subcontract', 'code' => '5650', 'name' => 'Job Cost — Subcontract', 'type' => 'expense'],
            ['key' => 'job_cost_other', 'code' => '5660', 'name' => 'Job Cost — Other', 'type' => 'expense'],

            /*
             * Credit-normal expense accounts, and the oddness is the point (§7.3). Charging a job for internal
             * plant at an hourly rate is a cost on the job and a recovery against the department that owns the
             * plant — so the credit lands against expense rather than income, and what is left over is
             * over/under absorption. Booking these as income would report internal transfers as turnover.
             */
            ['key' => 'plant_hire_recovery', 'code' => '5670', 'name' => 'Plant Internal Hire Recovery', 'type' => 'expense', 'normal_balance' => 'credit', 'description' => 'Contra-expense: internal plant hire charged out to jobs'],
            ['key' => 'burden_absorbed', 'code' => '5680', 'name' => 'Labour Burden Absorbed', 'type' => 'expense', 'normal_balance' => 'credit', 'description' => 'Contra-expense: burden charged to jobs at a rate'],
            ['key' => 'absorption_variance', 'code' => '5690', 'name' => 'Over / Under Absorption', 'type' => 'expense', 'description' => 'What is left when what was charged out and what was actually spent do not meet'],
        ],
    ];

    public function run(): void
    {
        // What the company has already chosen is not this seeder's business: a re-run fills only the gaps.
        $map = ConstructionAccounts::map();

        foreach (self::ACCOUNTS as $parentCode => $accounts) {
            $parent = Account::query()->where('code', $parentCode)->first();

            if (! $parent) {
                // Warned rather than thrown: a seeder that takes the whole provisioning run down because one
                // group header is missing is harder to recover from than one that says which seeder to run
                // first.
                $this->command?->warn("Group account {$parentCode} missing; run ChartOfAccountsSeeder first.");

                continue;
            }

            foreach ($accounts as $data) {
                $key = $data['key'];
                unset($data['key']);

                if (isset($map[$key]) && Account::query()->whereKey($map[$key])->exists()) {
                    continue;
                }

                $map[$key] = $this->accountFor($data, $parent)->getKey();
            }
        }

        app(TenantSettings::class)->set(ConstructionAccounts::SETTING, $map);
    }

    /**
     * The account a line should use: this seeder's own from an earlier run, or a new one.
     *
     * Never re-asserted over a live chart — `ChartOfAccountsSeeder`'s reason, since costs are posted against these —
     * and never another account's code: the first version did `firstOrCreate(['code' => …])`, found 2600 Customer
     * Advances already there, and quietly left goods received not invoiced pointing at the customers' money.
     *
     * @param  array<string, mixed>  $data
     */
    private function accountFor(array $data, Account $parent): Account
    {
        $atCode = Account::query()->where('code', $data['code'])->first();

        if ($atCode && $atCode->name === $data['name']) {
            return $atCode;
        }

        if ($atCode) {
            return Account::query()->where('name', $data['name'])->first()
                ?? Account::create(['code' => $this->nextFreeCode($data['code'])] + $data + ['parent_id' => $parent->id]);
        }

        return Account::create($data + ['parent_id' => $parent->id]);
    }

    private function nextFreeCode(string $code): string
    {
        do {
            $code = (string) ((int) $code + 1);
        } while (Account::query()->where('code', $code)->exists());

        return $code;
    }
}
