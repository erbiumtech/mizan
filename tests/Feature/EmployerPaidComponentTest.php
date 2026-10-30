<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\PayComponents\Pages\CreatePayComponent;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\EobiReturn;
use App\Modules\Payroll\Services\StatutoryContributions;
use App\Modules\Payroll\Support\PayrollReports;
use Database\Seeders\StatutoryComponentSeeder;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * B14: the employer's contributions are the employer's.
 *
 * EOBI (employer), social security and the provident-fund match were deductions like any other, so a company
 * that put one on a package — which the EOBI return needs — took it out of the employee's net pay. A component
 * now says who pays it: the employer's never reduces net pay or counts as a deduction, shows as employer cost,
 * and posts as an expense against its payable.
 */
class EmployerPaidComponentTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'employer-cost@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->seed(\Database\Seeders\PayComponentSeeder::class);
        $this->seed(StatutoryComponentSeeder::class);

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'employer-cost-emp@test.local')->id,
            'employee_id' => 'EMP-ER', 'gender' => 'Male', 'phone' => '0300-1111111', 'nic' => '42101-1234567-1',
        ]);

        $setting = EmployeeSetting::create([
            'employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 40000,
        ]);

        $components = app(StatutoryContributions::class)->components();

        foreach ([StatutoryContributions::COMPONENT_EOBI_EMPLOYEE => 370, StatutoryContributions::COMPONENT_EOBI_EMPLOYER => 1850] as $code => $amount) {
            EmployeeSettingComponent::create(['employee_setting_id' => $setting->id, 'pay_component_id' => $components[$code]->id, 'amount' => $amount]);
        }
    }

    private function payslip(string $month = 'July'): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'month' => $month,
            'total_working_days' => 22, 'paid_days' => 22,
        ])->fresh();
    }

    public function test_the_seeder_ships_the_employer_contributions_as_employer_paid(): void
    {
        $paidBy = PayComponent::query()->pluck('paid_by', 'code');

        $this->assertSame(PayComponent::PAID_BY_EMPLOYER, $paidBy[StatutoryContributions::COMPONENT_EOBI_EMPLOYER]);
        $this->assertSame(PayComponent::PAID_BY_EMPLOYER, $paidBy[StatutoryContributions::COMPONENT_SOCIAL_SECURITY]);
        $this->assertSame(PayComponent::PAID_BY_EMPLOYER, $paidBy[StatutoryContributions::COMPONENT_PF_EMPLOYER]);
        $this->assertSame(PayComponent::PAID_BY_EMPLOYEE, $paidBy[StatutoryContributions::COMPONENT_EOBI_EMPLOYEE]);
    }

    /** The migration's backfill, run over rows that look like a company migrated before it. */
    public function test_the_migration_backfills_existing_employer_components(): void
    {
        PayComponent::query()->update(['paid_by' => PayComponent::PAID_BY_EMPLOYEE, 'expense_account_key' => null, 'expense_account_id' => null]);

        $migration = require base_path('database/migrations/tenant/2026_10_30_200500_pay_component_paid_by.php');
        $migration->backfill();

        $employer = PayComponent::where('paid_by', PayComponent::PAID_BY_EMPLOYER)->orderBy('code')->get();

        $this->assertSame(['eobi_employer', 'provident_fund_employer', 'social_security_employer'], $employer->pluck('code')->all());
        $this->assertSame(['basic_wage'], $employer->pluck('expense_account_key')->unique()->values()->all());
        $this->assertSame(0, PayComponent::where('paid_by', PayComponent::PAID_BY_EMPLOYER)->whereNotIn('code', ['eobi_employer', 'provident_fund_employer', 'social_security_employer'])->count());
    }

    public function test_the_employers_contribution_never_reduces_net_pay_or_counts_as_a_deduction(): void
    {
        $payslip = $this->payslip();

        $this->assertSame(40000.0, (float) $payslip->total_earnings);
        $this->assertSame(370.0, (float) $payslip->total_deductions, 'only the employee\'s EOBI is a deduction');
        $this->assertSame(39630.0, (float) $payslip->net_salary);
        $this->assertSame(1850.0, $payslip->employerCost(), 'recorded on the payslip as employer cost');
    }

    /** The EOBI return keeps reading the employer's share off the payslip. */
    public function test_the_eobi_return_still_reads_the_employer_share(): void
    {
        $this->payslip();

        $rows = app(EobiReturn::class)->rows('July', $this->fiscalYear);

        $this->assertSame(370.0, $rows[0]['employee_contribution']);
        $this->assertSame(1850.0, $rows[0]['employer_contribution']);
    }

    /** Posted as an expense against its payable, and the entry still balances with salaries payable at net. */
    public function test_the_employers_contribution_posts_as_expense_against_its_payable(): void
    {
        $payslip = $this->payslip();

        $entry = JournalEntry::forSource(Payslip::class, $payslip->id)->with('lines')->firstOrFail();
        $line = fn (string $code, string $side): float => round((float) $entry->lines
            ->where('account_id', Account::where('code', $code)->value('id'))->sum($side), 2);

        $this->assertSame(round((float) $entry->lines->sum('debit_amount'), 2), round((float) $entry->lines->sum('credit_amount'), 2));
        $this->assertSame(40000.0 + 1850.0, $line(config('accounting.payroll_accounts.basic_wage'), 'debit_amount'), 'basic wage plus the employer\'s cost, by the mapping the component names');
        $this->assertSame(370.0 + 1850.0, $line(config('accounting.payroll_accounts.eobi_payable'), 'credit_amount'), 'both shares owed to EOBI');
        $this->assertSame(39630.0, $line(config('accounting.payroll_accounts.salaries_payable'), 'credit_amount'));
    }

    /** The expense account is the component's own choice — an account, not only a mapping key. */
    public function test_the_expense_account_is_chosen_on_the_component(): void
    {
        $misc = Account::where('code', '5900')->firstOrFail();
        PayComponent::where('code', StatutoryContributions::COMPONENT_EOBI_EMPLOYER)->firstOrFail()
            ->update(['expense_account_id' => $misc->id, 'expense_account_key' => null]);

        $payslip = $this->payslip();
        $entry = JournalEntry::forSource(Payslip::class, $payslip->id)->with('lines')->firstOrFail();

        $this->assertSame(1850.0, round((float) $entry->lines->where('account_id', $misc->id)->sum('debit_amount'), 2));
    }

    /** Chosen on the component's own form: who pays, and the expense account the cost lands on. */
    public function test_the_form_records_who_pays_and_where_it_is_expensed(): void
    {
        $misc = Account::where('code', '5900')->firstOrFail();

        Livewire::test(CreatePayComponent::class)
            ->fillForm([
                'label' => 'Group life (employer)', 'code' => 'group_life', 'kind' => PayComponent::KIND_DEDUCTION,
                'paid_by' => PayComponent::PAID_BY_EMPLOYER, 'expense_account_id' => $misc->id, 'expense_account_key' => null,
                'account_key' => 'eobi_payable',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $component = PayComponent::where('code', 'group_life')->firstOrFail();
        $this->assertTrue($component->isEmployerPaid());
        $this->assertSame($misc->id, $component->expenseAccountId());
    }

    public function test_an_employer_paid_component_needs_an_expense_account(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PayComponent::create([
            'code' => 'group_life', 'label' => 'Group life', 'kind' => PayComponent::KIND_DEDUCTION,
            'paid_by' => PayComponent::PAID_BY_EMPLOYER, 'account_key' => 'eobi_payable',
        ]);
    }

    /** An earning is never "the employer's" on top of pay — it already is pay. */
    public function test_an_earning_cannot_be_employer_paid(): void
    {
        $component = PayComponent::create([
            'code' => 'fuel_card', 'label' => 'Fuel card', 'kind' => PayComponent::KIND_EARNING,
            'paid_by' => PayComponent::PAID_BY_EMPLOYER, 'account_key' => 'bonus_overtime',
        ]);

        $this->assertSame(PayComponent::PAID_BY_EMPLOYEE, $component->fresh()->paid_by);
    }

    public function test_the_run_and_the_register_show_the_employer_cost(): void
    {
        $this->payslip();

        $run = PayrollRun::forMonth('July', $this->fiscalYear);
        $this->assertSame(1850.0, $run->totals()['employer_cost']);
        $this->assertSame(39630.0, $run->totals()['net']);

        $register = app(PayrollReports::class)->register('2026-07-31');
        $column = array_search('Employer cost', $register['columns'], true);
        $net = array_search('Net', $register['columns'], true);

        $this->assertNotFalse($column);
        $this->assertSame('1,850', $register['rows'][0][$column]);
        $this->assertSame('39,630', $register['rows'][0][$net], 'net is earnings less the employee\'s deductions only');
        $this->assertSame(1850.0, collect($register['tiles'])->firstWhere('label', 'EMPLOYER COST')['value']);
    }

    public function test_the_payslip_lists_the_employer_contribution_apart_from_the_deductions(): void
    {
        $payslip = $this->payslip()->load('employee.user', 'fiscalYear');

        $html = view('pdfs.payslip', ['data' => $payslip, 'verification_qr' => null])->render();

        $this->assertStringContainsString('Employer contributions (not deducted from your pay)', $html);
        $this->assertStringContainsString('EOBI (employer)', $html);
        $this->assertStringContainsString('EOBI (employee)', $html);
    }
}
