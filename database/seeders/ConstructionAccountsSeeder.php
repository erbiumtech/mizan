<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
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
     * @var array<string, array<int, array<string, mixed>>>
     */
    private const ACCOUNTS = [
        '1000' => [
            ['code' => '1600', 'name' => 'Materials on Site', 'type' => 'asset', 'description' => 'Delivered to site and not yet built in; claimable where the contract allows it'],
            ['code' => '1610', 'name' => 'Contract Assets', 'type' => 'asset', 'description' => 'Work done and not yet certified — the uncertified half of work in progress'],
            ['code' => '1620', 'name' => 'Retention Receivable', 'type' => 'asset', 'description' => 'Retention held by the employer: earned, contractually owed, not yet payable'],
        ],
        '2000' => [
            ['code' => '2600', 'name' => 'Goods Received Not Invoiced', 'type' => 'liability', 'description' => 'Received on site against a purchase order with no supplier invoice yet'],
            ['code' => '2610', 'name' => 'Accrued Subcontract Costs', 'type' => 'liability', 'description' => 'Subcontract work done and not yet certified or invoiced'],
            ['code' => '2620', 'name' => 'Retention Payable', 'type' => 'liability', 'description' => 'Retention held from subcontractors, owed back to them'],
            ['code' => '2630', 'name' => 'Contract Liabilities', 'type' => 'liability', 'description' => 'Advance payments received and certified value in excess of work done'],
            ['code' => '2640', 'name' => 'Provision for Foreseeable Losses', 'type' => 'liability', 'description' => 'A loss-making contract is provided for in full as soon as it is foreseen (§4.4)'],
        ],
        '4000' => [
            ['code' => '4600', 'name' => 'Contract Revenue', 'type' => 'income', 'description' => 'Certified value of construction work, gross of retention'],
        ],
        '5000' => [
            // Job cost by type, one account each: the labour/material/plant/subcontract split is the first cut
            // anybody asks for, and a single job-cost account cannot answer it (§3.2).
            ['code' => '5620', 'name' => 'Job Cost — Labour', 'type' => 'expense'],
            ['code' => '5630', 'name' => 'Job Cost — Material', 'type' => 'expense'],
            ['code' => '5640', 'name' => 'Job Cost — Plant', 'type' => 'expense'],
            ['code' => '5650', 'name' => 'Job Cost — Subcontract', 'type' => 'expense'],
            ['code' => '5660', 'name' => 'Job Cost — Other', 'type' => 'expense'],

            /*
             * Credit-normal expense accounts, and the oddness is the point (§7.3). Charging a job for internal
             * plant at an hourly rate is a cost on the job and a recovery against the department that owns the
             * plant — so the credit lands against expense rather than income, and what is left over is
             * over/under absorption. Booking these as income would report internal transfers as turnover.
             */
            ['code' => '5670', 'name' => 'Plant Internal Hire Recovery', 'type' => 'expense', 'normal_balance' => 'credit', 'description' => 'Contra-expense: internal plant hire charged out to jobs'],
            ['code' => '5680', 'name' => 'Labour Burden Absorbed', 'type' => 'expense', 'normal_balance' => 'credit', 'description' => 'Contra-expense: burden charged to jobs at a rate'],
            ['code' => '5690', 'name' => 'Over / Under Absorption', 'type' => 'expense', 'description' => 'What is left when what was charged out and what was actually spent do not meet'],
        ],
    ];

    public function run(): void
    {
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
                // firstOrCreate for the reason `ChartOfAccountsSeeder` gives at length: on every run but the
                // first these codes are a live chart with costs posted against them, and re-asserting a name
                // or a parent over that is not this seeder's business.
                Account::firstOrCreate(
                    ['code' => $data['code']],
                    $data + ['parent_id' => $parent->id],
                );
            }
        }
    }
}
