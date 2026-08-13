<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponent;
use App\Modules\Payroll\Services\EobiReturn;
use App\Modules\Payroll\Services\StatutoryContributions;
use Database\Seeders\StatutoryComponentSeeder;
use Tests\AccountingTestCase;

/**
 * Phase 9: EOBI, provincial social security, provident fund and the minimum-wage floor —
 * docs/hrms-plan.md §6.
 *
 * Three assertions carry this file, and all three are about restraint:
 *
 *  1. **EOBI is a percentage of the MINIMUM WAGE, not of pay.** The detail most often got
 *     wrong, and the error is several-fold rather than marginal.
 *  2. **Minimum wage warns and never adjusts.** Silently raising a figure would hide a
 *     compliance breach and misstate the agreed package at once.
 *  3. **Nothing is applied automatically.** The service computes; a person enters. A
 *     return reports what payslips actually carry rather than what should have been
 *     deducted, because a file full of contributions nobody deducted is a false
 *     declaration.
 */
class StatutoryContributionsTest extends AccountingTestCase
{
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'statutory@test.local'));

        $this->employee = Employee::create([
            'employee_id' => 'EMP-STAT-1',
            'name' => 'Statutory Test',
            'phone' => '0300-0000000',
            'gender' => 'Male',
            'is_active' => 1,
            'nic' => '42101-1234567-1',
        ]);
    }

    private function givePackage(float $basic): EmployeeSetting
    {
        return EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => $basic,
        ]);
    }

    // ---------------------------------------------------------------- EOBI

    /**
     * The detail most often got wrong: the basis is the minimum wage, not the salary.
     *
     * A 1% employee rate on a 37,000 basis is 370 whatever somebody earns. Applied to a
     * 200,000 salary it would be 2,000 — five times too much, and nobody notices until the
     * scheme does.
     */
    public function test_eobi_is_a_percentage_of_the_minimum_wage_not_of_pay(): void
    {
        config([
            'statutory.eobi.wage_basis' => 37000,
            'statutory.eobi.employee_rate' => 0.01,
            'statutory.eobi.employer_rate' => 0.05,
        ]);

        $lowPaid = app(StatutoryContributions::class)->eobi();

        $this->givePackage(500000);
        $highPaid = app(StatutoryContributions::class)->for($this->employee);

        $this->assertSame(370.0, $lowPaid['eobi_employee']);
        $this->assertSame(1850.0, $lowPaid['eobi_employer']);

        // The same figures for a half-million salary, which is the point.
        $this->assertSame(370.0, $highPaid['eobi_employee']);
        $this->assertSame(1850.0, $highPaid['eobi_employer']);
    }

    // ---------------------------------------------------------------- social security

    /** The ceiling is the point: above it, the contribution stops rising. */
    public function test_social_security_is_capped_at_the_wage_ceiling(): void
    {
        config([
            'statutory.social_security.sindh' => ['employer_rate' => 0.06, 'wage_ceiling' => 25000],
        ]);

        $service = app(StatutoryContributions::class);

        // Below the ceiling: 6% of the wage.
        $this->assertSame(1200.0, $service->socialSecurity(20000, 'sindh'));

        // At and above it: 6% of the ceiling, and no more.
        $this->assertSame(1500.0, $service->socialSecurity(25000, 'sindh'));
        $this->assertSame(1500.0, $service->socialSecurity(500000, 'sindh'));
    }

    /** Provinces are not one scheme, and the setting decides which applies. */
    public function test_the_province_setting_decides_which_rules_apply(): void
    {
        config([
            'statutory.social_security.sindh' => ['employer_rate' => 0.06, 'wage_ceiling' => 25000],
            'statutory.social_security.punjab' => ['employer_rate' => 0.05, 'wage_ceiling' => 20000],
        ]);

        $service = app(StatutoryContributions::class);

        $this->assertSame(1500.0, $service->socialSecurity(100000, 'sindh'));
        $this->assertSame(1000.0, $service->socialSecurity(100000, 'punjab'));
    }

    /** An unrecognised province falls back rather than throwing during a payroll run. */
    public function test_an_unknown_province_falls_back_to_the_default(): void
    {
        app(\App\Support\TenantSettings::class)->set('statutory.social_security.default_province', 'atlantis');

        $this->assertSame('sindh', app(StatutoryContributions::class)->province());
    }

    // ---------------------------------------------------------------- provident fund

    /** Off by default: voluntary for most establishments. */
    public function test_the_provident_fund_is_off_unless_switched_on(): void
    {
        config(['statutory.provident_fund.enabled' => false]);

        $pf = app(StatutoryContributions::class)->providentFund(100000);

        $this->assertSame(0.0, $pf['provident_fund_employee']);
        $this->assertSame(0.0, $pf['provident_fund_employer']);
    }

    public function test_the_provident_fund_matches_when_switched_on(): void
    {
        config([
            'statutory.provident_fund.enabled' => true,
            'statutory.provident_fund.employee_rate' => 0.1,
            'statutory.provident_fund.employer_rate' => 0.1,
        ]);

        $pf = app(StatutoryContributions::class)->providentFund(100000);

        $this->assertSame(10000.0, $pf['provident_fund_employee']);
        $this->assertSame(10000.0, $pf['provident_fund_employer']);
    }

    // ---------------------------------------------------------------- minimum wage

    /**
     * THE restraint test: a breach warns and changes nothing.
     *
     * Quietly raising the figure would hide the employer's compliance problem AND misstate
     * what was agreed — two wrongs from one line of code, which is the same reasoning §4.2
     * applies to overtime caps.
     */
    public function test_a_minimum_wage_breach_warns_and_adjusts_nothing(): void
    {
        config(['statutory.minimum_wage.sindh' => 37000]);

        $setting = $this->givePackage(30000);

        $warnings = app(StatutoryContributions::class)->warnings(30000, 'sindh', $this->employee);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('below the Sindh minimum', $warnings[0]);
        $this->assertStringContainsString('Nothing has been adjusted', $warnings[0]);

        // And the package is exactly as agreed.
        $this->assertSame(30000.0, (float) $setting->fresh()->basic_wage);
    }

    public function test_a_package_at_or_above_the_minimum_does_not_warn(): void
    {
        config(['statutory.minimum_wage.sindh' => 37000]);

        $this->assertSame([], app(StatutoryContributions::class)->warnings(37000, 'sindh', $this->employee));
        $this->assertSame([], app(StatutoryContributions::class)->warnings(50000, 'sindh', $this->employee));
    }

    /** The company-wide report a payroll clerk actually needs. */
    public function test_breaches_are_reported_across_the_company(): void
    {
        config(['statutory.minimum_wage.sindh' => 37000]);

        $this->givePackage(30000);

        $this->assertCount(1, app(StatutoryContributions::class)->minimumWageBreaches());
    }

    // ---------------------------------------------------------------- components

    /**
     * Seeding creates the COMPONENTS and no amounts.
     *
     * "This company may deduct EOBI" is a different statement from "this company deducts
     * EOBI from Ali", and only a person makes the second.
     */
    public function test_seeding_creates_components_and_deducts_nothing(): void
    {
        $this->seed(StatutoryComponentSeeder::class);

        $components = app(StatutoryContributions::class)->components();

        $this->assertArrayHasKey(StatutoryContributions::COMPONENT_EOBI_EMPLOYEE, $components);
        $this->assertArrayHasKey(StatutoryContributions::COMPONENT_SOCIAL_SECURITY, $components);

        // Each posts to its own liability account rather than the shared ESI one: every
        // scheme files its own return, and one account holding three cannot be reconciled
        // against any of them.
        $this->assertSame(
            'eobi_payable',
            $components[StatutoryContributions::COMPONENT_EOBI_EMPLOYEE]->account_key,
        );

        // Nothing is on anybody's package.
        $this->assertSame(0, \App\Modules\Payroll\Models\EmployeeSettingComponent::count());
    }

    public function test_seeding_twice_does_not_duplicate_components(): void
    {
        $this->seed(StatutoryComponentSeeder::class);
        $this->seed(StatutoryComponentSeeder::class);

        $this->assertSame(
            1,
            PayComponent::where('code', StatutoryContributions::COMPONENT_EOBI_EMPLOYEE)->count(),
        );
    }

    // ---------------------------------------------------------------- the EOBI return

    /**
     * The return reports what payslips CARRY, not what should have been deducted.
     *
     * A file full of contributions nobody deducted would be a false declaration, which is
     * a worse outcome than an empty return that says why it is empty.
     */
    public function test_the_return_reports_what_the_payslips_carry(): void
    {
        $this->seed(StatutoryComponentSeeder::class);
        $this->givePackage(200000);

        $payslip = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
        ]);

        // Empty until something is actually on the payslip.
        $this->assertCount(0, app(EobiReturn::class)->rows('July', $this->fiscalYear));
        $this->assertStringContainsString(
            'not on anybody\'s package',
            app(EobiReturn::class)->readiness('July', $this->fiscalYear),
        );

        $components = app(StatutoryContributions::class)->components();

        PayslipComponent::create([
            'payslip_id' => $payslip->id,
            'pay_component_id' => $components[StatutoryContributions::COMPONENT_EOBI_EMPLOYEE]->id,
            'amount' => 370,
        ]);
        PayslipComponent::create([
            'payslip_id' => $payslip->id,
            'pay_component_id' => $components[StatutoryContributions::COMPONENT_EOBI_EMPLOYER]->id,
            'amount' => 1850,
        ]);

        $rows = app(EobiReturn::class)->rows('July', $this->fiscalYear);

        $this->assertCount(1, $rows);
        $this->assertSame('EMP-STAT-1', $rows[0]['employee_code']);
        $this->assertSame('42101-1234567-1', $rows[0]['cnic']);
        $this->assertSame(370.0, $rows[0]['employee_contribution']);
        $this->assertSame(1850.0, $rows[0]['employer_contribution']);

        $summary = app(EobiReturn::class)->summary('July', $this->fiscalYear);
        $this->assertSame(1, $summary['employees']);
        $this->assertSame(2220.0, $summary['grand_total']);

        $csv = app(EobiReturn::class)->csv('July', $this->fiscalYear);
        $this->assertStringContainsString('EMP-STAT-1', $csv);
        $this->assertStringContainsString('370.00,1850.00', $csv);
    }

    /**
     * "No rows" and "you have not set this up" look identical in a CSV.
     *
     * Only one of them is somebody's fault, so the difference is said in words.
     */
    public function test_the_return_says_why_it_is_empty_when_the_component_is_missing(): void
    {
        $readiness = app(EobiReturn::class)->readiness('July', $this->fiscalYear);

        $this->assertStringContainsString('does not exist in this company', $readiness);
    }
}
