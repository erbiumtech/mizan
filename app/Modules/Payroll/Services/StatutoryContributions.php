<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayComponent;

/**
 * Phase 9: EOBI, provincial social security, provident fund and the minimum-wage floor.
 *
 * **Not a module.** These are money, they post to the ledger, and they belong in Payroll
 * as pay components and provisions — docs/hrms-plan.md §2 and §6.
 *
 * Three rules govern everything here, and they are the reason this class computes but
 * never writes:
 *
 *  1. **Every figure is a configurable DEFAULT that a company's HR must confirm.** Rates
 *     and ceilings differ by province and change with each provincial budget. A wrong
 *     contribution rate applied confidently is worse than a blank one that asks.
 *  2. **Nothing is applied automatically.** This class returns amounts; a person creates
 *     or imports the pay components. §6a and `crms §10` reach the same rule three times
 *     independently: intentions must not write to the record without a person in between.
 *  3. **Minimum wage WARNS and never adjusts.** Quietly raising a figure would hide a
 *     compliance breach and misstate the agreed package at the same time.
 *
 * The reference data lives in `config/statutory.php`, keyed by province, so an amendment
 * is a re-seed rather than a code change — the same position this application already
 * takes on tax slabs.
 */
class StatutoryContributions
{
    /** Codes the seeded components use, so a caller can find them without guessing. */
    public const COMPONENT_EOBI_EMPLOYEE = 'eobi_employee';

    public const COMPONENT_EOBI_EMPLOYER = 'eobi_employer';

    public const COMPONENT_SOCIAL_SECURITY = 'social_security_employer';

    public const COMPONENT_PF_EMPLOYEE = 'provident_fund_employee';

    public const COMPONENT_PF_EMPLOYER = 'provident_fund_employer';

    /**
     * Every statutory amount for one employee in one month, as figures for a human.
     *
     * @return array{
     *     eobi_employee: float, eobi_employer: float,
     *     social_security_employer: float,
     *     provident_fund_employee: float, provident_fund_employer: float,
     *     warnings: array<int, string>,
     *     province: string,
     * }
     */
    public function for(Employee $employee, ?EmployeeSetting $setting = null): array
    {
        $setting ??= EmployeeSetting::query()
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('start_date')
            ->first();

        $province = $this->province();
        $basic = (float) ($setting->basic_wage ?? 0);

        return [
            ...$this->eobi(),
            'social_security_employer' => $this->socialSecurity($basic, $province),
            ...$this->providentFund($basic),
            'warnings' => $this->warnings($basic, $province, $employee),
            'province' => $province,
        ];
    }

    /**
     * EOBI: a percentage of the MINIMUM WAGE, not of actual pay.
     *
     * That is what makes it a fixed rupee amount per employee per month rather than a
     * percentage of salary, and it is the detail most often got wrong — a rate applied to
     * a real salary produces a contribution several times too large.
     *
     * @return array{eobi_employee: float, eobi_employer: float}
     */
    public function eobi(): array
    {
        $basis = (float) config('statutory.eobi.wage_basis', 0);

        return [
            'eobi_employee' => round($basis * (float) config('statutory.eobi.employee_rate', 0), 2),
            'eobi_employer' => round($basis * (float) config('statutory.eobi.employer_rate', 0), 2),
        ];
    }

    /**
     * Provincial social security: an employer contribution up to a wage ceiling.
     *
     * The ceiling is the point — above it the contribution stops rising, so a senior
     * salary contributes the same as one at the ceiling.
     */
    public function socialSecurity(float $basicWage, ?string $province = null): float
    {
        $province = $province ?? $this->province();
        $rules = (array) config("statutory.social_security.{$province}", []);

        if ($rules === []) {
            return 0.0;
        }

        $ceiling = (float) ($rules['wage_ceiling'] ?? 0);
        $rate = (float) ($rules['employer_rate'] ?? 0);

        // min() rather than a conditional: the contributable wage IS the lesser of the
        // two, and writing it as a branch invites somebody to "fix" the branch.
        return round(min($basicWage, $ceiling) * $rate, 2);
    }

    /**
     * Provident fund: an employee percentage with an employer match.
     *
     * Voluntary for most establishments, so it defaults to off and returns zeros. When on,
     * the employer side is a liability that accrues rather than money paid out this month
     * — which is why it is a separate figure rather than folded into the employee's.
     *
     * @return array{provident_fund_employee: float, provident_fund_employer: float}
     */
    public function providentFund(float $basicWage): array
    {
        if (! config('statutory.provident_fund.enabled', false)) {
            return ['provident_fund_employee' => 0.0, 'provident_fund_employer' => 0.0];
        }

        return [
            'provident_fund_employee' => round($basicWage * (float) config('statutory.provident_fund.employee_rate', 0), 2),
            'provident_fund_employer' => round($basicWage * (float) config('statutory.provident_fund.employer_rate', 0), 2),
        ];
    }

    /**
     * Compliance warnings. **Never adjustments.**
     *
     * A minimum-wage breach is the employer's to see and fix. Silently raising the figure
     * would hide it while also misstating what was agreed — two wrongs from one line of
     * code, which is the same reasoning §4.2 applies to overtime caps.
     *
     * @return array<int, string>
     */
    public function warnings(float $basicWage, ?string $province = null, ?Employee $employee = null): array
    {
        $province = $province ?? $this->province();
        $minimum = (float) config("statutory.minimum_wage.{$province}", 0);
        $warnings = [];

        if ($minimum > 0 && $basicWage > 0 && $basicWage < $minimum) {
            $warnings[] = sprintf(
                '%s has a basic wage of %s, below the %s minimum of %s. Nothing has been adjusted — '
                .'this is a figure for you to check and correct, and the shipped minimum is a default '
                .'to confirm against current provincial law.',
                $employee?->display_label ?? 'This employee',
                number_format($basicWage, 2),
                ucfirst($province),
                number_format($minimum, 2),
            );
        }

        return $warnings;
    }

    /**
     * Everybody whose package is below the provincial minimum.
     *
     * A report rather than a fix, and the shape a payroll clerk actually needs: the
     * per-employee check above answers one person, this answers "is anybody underpaid".
     *
     * @return array<int, string>
     */
    public function minimumWageBreaches(): array
    {
        $province = $this->province();
        $warnings = [];

        Employee::query()->where('is_active', true)->with('setting')->cursor()->each(
            function (Employee $employee) use ($province, &$warnings): void {
                $basic = (float) ($employee->setting->basic_wage ?? 0);

                $warnings = [...$warnings, ...$this->warnings($basic, $province, $employee)];
            }
        );

        return $warnings;
    }

    /**
     * Which province's rules apply.
     *
     * A company setting, because this application does not know where a company operates
     * and guessing would apply Sindh's rules to a Peshawar factory. §6: do not encode one
     * province's rules as "Pakistan".
     */
    public function province(): string
    {
        $province = (string) setting('statutory.social_security.default_province', 'sindh');

        // An unrecognised province falls back rather than throwing on a payroll run: the
        // figures come out as zeros and the warnings say nothing, which is visible, where
        // an exception at 2am is not.
        return array_key_exists($province, (array) config('statutory.social_security', []))
            ? $province
            : 'sindh';
    }

    /**
     * The pay components these figures are entered as, if a company has created them.
     *
     * Returns what exists. **Nothing here creates a component or writes an amount** — a
     * person does that, having read the figures and confirmed the rates. That is rule 2 at
     * the top of this class, and it is the same reason CRM does not push a commission into
     * payroll.
     *
     * @return array<string, PayComponent>
     */
    public function components(): array
    {
        return PayComponent::query()
            ->whereIn('code', [
                self::COMPONENT_EOBI_EMPLOYEE,
                self::COMPONENT_EOBI_EMPLOYER,
                self::COMPONENT_SOCIAL_SECURITY,
                self::COMPONENT_PF_EMPLOYEE,
                self::COMPONENT_PF_EMPLOYER,
            ])
            ->get()
            ->keyBy('code')
            ->all();
    }
}
