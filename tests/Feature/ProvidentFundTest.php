<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Lifecycle\Services\FinalSettlementBuilder;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\ProvidentFundEntry;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use App\Modules\Payroll\Services\ProvidentFund;
use App\Support\Contracts\ProvidentFundBalance;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The provident-fund ledger — docs/saas-launch-plan.md §14.1. Append-only per
 * employee; contributions arrive when a run is signed off, once per payslip;
 * the final settlement reads the balance through the contract.
 */
class ProvidentFundTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-10 10:00:00');
        $this->actingAs($this->makeUser('Administrator', 'pf-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting', 'lifecycle'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();
        $this->seed(\Database\Seeders\TransactionTypeSeeder::class);
        config(['statutory.provident_fund.enabled' => true, 'statutory.provident_fund.employee_rate' => 0.1, 'statutory.provident_fund.employer_rate' => 0.1]);

        $this->employee = Employee::create(['user_id' => $this->makeUser('Employee', 'pf-emp@test.local')->id, 'employee_id' => 'EMP-PF', 'name' => 'Farah', 'gender' => 'Female', 'is_active' => 1, 'bank_account_no' => '1', 'date_of_joining' => '2020-01-01']);
        EmployeeSetting::create(['employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 50000]);
    }

    public function test_the_ledger_is_append_only_and_sums_signed(): void
    {
        $fund = app(ProvidentFund::class);
        $opening = $fund->record($this->employee, ProvidentFundEntry::KIND_OPENING, 30000, 30000, 'Old register', '2026-07-01');
        $fund->record($this->employee, ProvidentFundEntry::KIND_PROFIT, 1500, 1500, 'FY profit', '2026-08-31');
        $fund->record($this->employee, ProvidentFundEntry::KIND_WITHDRAWAL, 10000, 0, 'Loan against fund', '2026-09-01');

        $this->assertSame(53000.0, $fund->balanceFor($this->employee->id));
        $this->assertSame(63000.0, $fund->balanceFor($this->employee->id, Carbon::parse('2026-08-31')), 'to a date');
        $this->assertSame(['employee' => 30000.0, 'employer' => 30000.0, 'profit' => 3000.0, 'withdrawn' => 10000.0, 'balance' => 53000.0], $fund->summary($this->employee->id));

        try {
            $opening->update(['employee_amount' => 1]);
            $this->fail('An entry was edited.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('never edited', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $fund->record($this->employee, 'bonus', 1);
    }

    public function test_signing_off_a_run_records_the_months_contribution_once(): void
    {
        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);
        $run = PayrollRun::forMonth('August', $this->fiscalYear);
        $this->assertSame(0, ProvidentFundEntry::count(), 'nothing until it is signed off');

        $run->lock(auth()->user());
        $entry = ProvidentFundEntry::sole();
        $this->assertSame(ProvidentFundEntry::KIND_CONTRIBUTION, $entry->kind);
        $this->assertSame(5000.0, (float) $entry->employee_amount);
        $this->assertSame(5000.0, (float) $entry->employer_amount);
        $this->assertSame('2026-08-31', $entry->entry_date->toDateString());
        $this->assertSame(10000.0, app(ProvidentFundBalance::class)->balanceFor($this->employee->id));

        $run->fresh()->reopen(auth()->user(), 'A late claim');
        $run->fresh()->lock(auth()->user());
        $this->assertSame(1, ProvidentFundEntry::count(), 'once per payslip, however often the run is signed off');

        config(['statutory.provident_fund.enabled' => false]);
        app(MonthlyPayrollService::class)->openMonth('September', $this->fiscalYear);
        PayrollRun::forMonth('September', $this->fiscalYear)->lock(auth()->user());
        $this->assertSame(1, ProvidentFundEntry::count(), 'no fund, no entry');
    }

    public function test_the_final_settlement_pays_the_fund_out(): void
    {
        app(ProvidentFund::class)->record($this->employee, ProvidentFundEntry::KIND_OPENING, 20000, 20000, 'Old register', '2026-07-01');
        app(ProvidentFund::class)->record($this->employee, ProvidentFundEntry::KIND_PROFIT, 1000, 0, 'Later', '2026-12-31');

        $settlement = app(FinalSettlementBuilder::class)->build($this->employee, '2026-09-30');

        $this->assertSame(40000.0, (float) $settlement->provident_fund_amount, 'the balance to the leaving date, not after it');
        $this->assertSame($settlement->computedNet(), (float) $settlement->net_amount);
        $this->assertGreaterThanOrEqual(40000.0, (float) $settlement->net_amount);
    }
}
