<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\WithholdingSection;
use Illuminate\Database\Seeder;

/**
 * The §153 rates a Pakistani company withholds at — `docs/erpnext-gap-plan.md` Phase 4.
 *
 * Reference data rather than company data, which is why it is seeded and has no maintenance screen: the
 * rates and the thresholds are national law, and the decisions that belong to a company are *which supplier
 * each section applies to* and *whether that supplier files* — both of which live on the beneficiary, where
 * there is a form for them.
 *
 * **Seeding these withholds nothing.** A section only ever applies to a beneficiary somebody has assigned
 * it to, so a company that provisions today gets four rows and the same behaviour it had yesterday.
 *
 * **Confirm the figures before relying on them.** They are the rates in general use for the current tax
 * year and they move with every Finance Act. When one changes, the honest change here is a *new row* with
 * the later `effective_from` and an `effective_to` on the row it supersedes — never an edit, because a
 * deduction made last September has to stay the rate that applied last September.
 */
class WithholdingSectionSeeder extends Seeder
{
    /**
     * Where these rates start from.
     *
     * The tax year the application is being seeded for, and not `now()`: a company provisioned in the
     * middle of a year still needs a section that covers a payment dated at its start.
     */
    private const EFFECTIVE_FROM = '2025-07-01';

    public function run(): void
    {
        $account = Account::where('code', WithholdingSection::DEFAULT_ACCOUNT_CODE)->value('id');

        $sections = [
            [
                'section' => '153(1)(a)',
                'label' => 'Sale of goods',
                'rate_filer' => 5.5,
                'rate_non_filer' => 11,
                // Aggregate over the year: a company buying twice from a small supplier withholds nothing,
                // and the same supplier's tenth delivery is withheld from.
                'per_payment_threshold' => null,
                'annual_threshold' => 100000,
            ],
            [
                'section' => '153(1)(b)',
                'label' => 'Services rendered',
                'rate_filer' => 11,
                'rate_non_filer' => 22,
                'per_payment_threshold' => null,
                'annual_threshold' => 30000,
            ],
            [
                'section' => '153(1)(b) — transport',
                'label' => 'Transport services',
                // The carve-out that catches every company with a goods-forwarding bill, and the reason a
                // single "services" row is not enough to be useful.
                'rate_filer' => 3,
                'rate_non_filer' => 6,
                'per_payment_threshold' => null,
                'annual_threshold' => 30000,
            ],
            [
                'section' => '153(1)(c)',
                'label' => 'Execution of a contract',
                'rate_filer' => 8,
                'rate_non_filer' => 16,
                // No threshold, deliberately: a contract is withheld from on the first payment.
                'per_payment_threshold' => null,
                'annual_threshold' => null,
            ],
        ];

        foreach ($sections as $section) {
            $existing = WithholdingSection::query()
                ->where('section', $section['section'])
                ->whereDate('effective_from', self::EFFECTIVE_FROM)
                ->first();

            if (! $existing) {
                WithholdingSection::create($section + [
                    'account_id' => $account,
                    'effective_from' => self::EFFECTIVE_FROM,
                    'effective_to' => null,
                    'is_active' => true,
                ]);

                continue;
            }

            /*
             * Re-running is how a company picks up a figure corrected in the code, so the statutory columns
             * are re-asserted — the position `TaxRateSeeder` takes for the same reason.
             *
             * What is never re-asserted is `is_active`. Switching a section off is a statement about this
             * company — it does not buy transport, it has no contractors — and a seeder that turned one back
             * on would start withholding tax from somebody's supplier on the strength of a deploy.
             */
            $existing->update($section + ['account_id' => $existing->account_id ?: $account]);
        }

        $this->command?->info('Seeded '.count($sections).' withholding sections. Confirm the rates against '
            .'the current Finance Act, then assign a section to the suppliers it applies to.');
    }
}
