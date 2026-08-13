<?php

namespace Database\Seeders;

use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Services\StatutoryContributions;
use Illuminate\Database\Seeder;

/**
 * The pay components the statutory schemes are entered as.
 *
 * **The components, not the amounts.** Creating a component says "this company may deduct
 * EOBI"; it does not deduct anything. An amount reaches a payslip only when somebody puts
 * it on an employee's package, having read the figures StatutoryContributions computes and
 * confirmed the rates against current provincial law.
 *
 * That separation is the whole of docs/hrms-plan.md §6a's rule, reached independently
 * three times in this plan family: intentions must not write to the record without a
 * person in between. It is also why a rate change is a re-seed of config rather than a
 * migration over everybody's pay.
 *
 * Each component posts to its own liability account. Deliberately not the shared ESI
 * account: every scheme files its own monthly return, and one account holding three of
 * them cannot be reconciled against any of them.
 *
 * firstOrCreate on `code`, so re-running adds what is missing and never overwrites a
 * company's edits.
 */
class StatutoryComponentSeeder extends Seeder
{
    public function run(): void
    {
        $components = [
            [
                'code' => StatutoryContributions::COMPONENT_EOBI_EMPLOYEE,
                'label' => 'EOBI (employee)',
                'kind' => PayComponent::KIND_DEDUCTION,
                'account_key' => 'eobi_payable',
                // A statutory deduction, not income foregone: it reduces take-home pay
                // and is not itself taxable earnings.
                'is_taxable' => false,
                'sort' => 100,
                'description' => 'Employee EOBI contribution. A percentage of the MINIMUM WAGE, not of actual pay — '
                    .'confirm the rate and the wage basis in config/statutory.php against current law.',
            ],
            [
                'code' => StatutoryContributions::COMPONENT_EOBI_EMPLOYER,
                'label' => 'EOBI (employer)',
                'kind' => PayComponent::KIND_DEDUCTION,
                'account_key' => 'eobi_payable',
                'is_taxable' => false,
                'sort' => 101,
                'description' => 'Employer EOBI contribution. An employer cost rather than an employee deduction — '
                    .'it does not reduce take-home pay, and a company tracking it here should say so on the payslip.',
            ],
            [
                'code' => StatutoryContributions::COMPONENT_SOCIAL_SECURITY,
                'label' => 'Social security (employer)',
                'kind' => PayComponent::KIND_DEDUCTION,
                'account_key' => 'social_security_payable',
                'is_taxable' => false,
                'sort' => 102,
                'description' => 'Provincial social security employer contribution, up to a wage ceiling. '
                    .'Rates and ceilings differ by province — SESSI, PESSI and their equivalents are not one scheme.',
            ],
            [
                'code' => StatutoryContributions::COMPONENT_PF_EMPLOYEE,
                'label' => 'Provident fund (employee)',
                'kind' => PayComponent::KIND_DEDUCTION,
                'account_key' => 'provident_fund_payable',
                'is_taxable' => false,
                'sort' => 103,
                'description' => 'Employee provident fund contribution. Voluntary for most establishments — '
                    .'off by default in config/statutory.php.',
            ],
            [
                'code' => StatutoryContributions::COMPONENT_PF_EMPLOYER,
                'label' => 'Provident fund (employer match)',
                'kind' => PayComponent::KIND_DEDUCTION,
                'account_key' => 'provident_fund_payable',
                'is_taxable' => false,
                'sort' => 104,
                'description' => 'Employer match, held for the fund. A liability that accrues rather than money '
                    .'paid out this month.',
            ],
        ];

        foreach ($components as $component) {
            PayComponent::firstOrCreate(['code' => $component['code']], $component);
        }
    }
}
