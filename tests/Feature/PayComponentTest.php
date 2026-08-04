<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\EmployeeSettingComponent;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Support\ModuleMap;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Pay as data instead of columns.
 *
 * Adding one allowance meant editing thirteen files, because every part of pay was a
 * column and every column was named in all of them. The plan's condition for this
 * being done: every existing payslip's gross and net identical before and after, and
 * adding an allowance touching one row and zero files. Both are tested here — the
 * second by adding an allowance in the test and asserting it is paid, taxed, posted
 * and recorded without a line of production code knowing it exists.
 */
class PayComponentTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'components@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->seed(\Database\Seeders\PayComponentSeeder::class);

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'paid@test.local')->id,
            'employee_id' => 'EMP-1',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);
    }

    private function setting(array $attributes = []): EmployeeSetting
    {
        if ($existing = EmployeeSetting::where('employee_id', $this->employee->id)->first()) {
            return $existing;
        }

        return EmployeeSetting::create(array_merge([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
            'medical_allowance' => 20000,
        ], $attributes));
    }

    private function payslip(string $month = 'July'): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    /** An allowance added as a row, with no code changed anywhere. */
    private function addAllowance(string $code, string $label, float $amount, array $attributes = []): PayComponent
    {
        $component = PayComponent::create(array_merge([
            'code' => $code,
            'label' => $label,
            'kind' => PayComponent::KIND_EARNING,
            'account_key' => 'bonus_overtime',
        ], $attributes));

        EmployeeSettingComponent::create([
            'employee_setting_id' => $this->setting()->getKey(),
            'pay_component_id' => $component->getKey(),
            'amount' => $amount,
        ]);

        return $component;
    }

    private function balanceOf(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        $query = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true));

        return round((float) $query->sum('debit_amount') - (float) (clone $query)->sum('credit_amount'), 2);
    }

    // ---- The shipped components are unchanged ------------------------------

    public function test_the_shipped_components_are_all_column_backed(): void
    {
        // They still have their own columns and the calculation still reads them.
        // Marking any of them data-driven would double-count that part of pay.
        $this->assertSame(0, PayComponent::dataDriven()->count());
        $this->assertSame(11, PayComponent::count());
    }

    public function test_a_payslip_with_no_extra_components_is_exactly_as_before(): void
    {
        $this->setting();

        $payslip = $this->payslip()->fresh();

        $this->assertSame(420000.0, (float) $payslip->total_earnings, 'basic plus medical, as the columns say');
    }

    public function test_what_a_payslip_paid_is_recorded_component_by_component(): void
    {
        $this->setting();

        $payslip = $this->payslip();

        $recorded = $payslip->components()->with('component')->get()
            ->mapWithKeys(fn ($row): array => [$row->component->code => round((float) $row->amount, 2)]);

        $this->assertSame(400000.0, $recorded['basic_wage']);
        $this->assertSame(20000.0, $recorded['medical_allowance']);
        $this->assertArrayNotHasKey('bonus', $recorded->all(), 'nothing is recorded for a component worth nothing');
    }

    public function test_the_recorded_components_add_up_to_the_stored_totals(): void
    {
        // The invariant the backfill migration checks across every existing payslip.
        $this->setting(['bonus' => 15000, 'meal_deduction' => 3250]);

        $payslip = $this->payslip()->fresh();

        $rows = $payslip->components()->with('component')->get();

        $gross = round($rows
            ->filter(fn ($r): bool => $r->component->isEarning() && $r->component->code !== 'expense_reimbursement')
            ->sum('amount'), 2);

        $deducted = round($rows->filter(fn ($r): bool => ! $r->component->isEarning())->sum('amount'), 2);

        $this->assertSame(round((float) $payslip->total_earnings, 2), $gross);
        $this->assertSame(round((float) $payslip->total_deductions, 2), $deducted);
    }

    // ---- The point: an allowance is a row ----------------------------------

    public function test_an_added_allowance_is_paid(): void
    {
        $this->addAllowance('fuel_card', 'Fuel Card', 12000);

        $payslip = $this->payslip()->fresh();

        $this->assertSame(432000.0, (float) $payslip->total_earnings, '420,000 plus the 12,000 allowance');
    }

    public function test_an_added_allowance_is_recorded_on_the_payslip(): void
    {
        $this->addAllowance('fuel_card', 'Fuel Card', 12000);

        $recorded = $this->payslip()->components()->with('component')->get()
            ->firstWhere('component.code', 'fuel_card');

        $this->assertNotNull($recorded);
        $this->assertSame(12000.0, round((float) $recorded->amount, 2));
    }

    public function test_an_added_allowance_is_posted_to_its_account(): void
    {
        // Without this the entry would not balance: net salary includes the allowance,
        // so the debit has to exist or the posting is refused.
        $this->addAllowance('fuel_card', 'Fuel Card', 12000);

        $payslip = $this->payslip();

        $entry = JournalEntry::where('source_type', ModuleMap::alias(Payslip::class))
            ->where('source_id', $payslip->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(
            round((float) $entry->lines()->sum('debit_amount'), 2),
            round((float) $entry->lines()->sum('credit_amount'), 2),
            'the entry balances with the allowance in it',
        );
        $this->assertStringContainsString('Fuel Card', $entry->lines()->pluck('description')->implode(' '));
    }

    public function test_an_added_deduction_reduces_net_pay(): void
    {
        $this->addAllowance('union_dues', 'Union Dues', 1500, [
            'kind' => PayComponent::KIND_DEDUCTION,
            'account_key' => 'esi_payable',
        ]);

        $payslip = $this->payslip()->fresh();

        $this->assertSame(420000.0, (float) $payslip->total_earnings, 'earnings are untouched');
        $this->assertSame(
            round((float) $payslip->withholding_tax + 1500, 2),
            round((float) $payslip->total_deductions, 2),
            'the dues are deducted on top of the tax',
        );
    }

    public function test_a_taxable_allowance_raises_the_tax(): void
    {
        // Against the same package with and without the allowance — not against an
        // employee who has no package at all, which is a comparison that proves
        // nothing and passes anyway.
        $this->setting();

        $plain = $this->payslip('July')->fresh();
        $taxBefore = round((float) $plain->withholding_tax, 2);

        $this->assertGreaterThan(0, $taxBefore, 'this wage is taxed to begin with');
        $plain->delete();

        $this->addAllowance('fuel_card', 'Fuel Card', 50000, ['is_taxable' => true]);

        $this->assertGreaterThan($taxBefore, round((float) $this->payslip('July')->fresh()->withholding_tax, 2));
    }

    /** A reimbursement is the employee's own money coming back, not income. */
    public function test_a_non_taxable_allowance_does_not(): void
    {
        $this->setting();

        $plain = $this->payslip('July')->fresh();
        $taxBefore = round((float) $plain->withholding_tax, 2);

        $this->assertGreaterThan(0, $taxBefore);
        $plain->delete();

        $this->addAllowance('travel_refund', 'Travel Refund', 50000, ['is_taxable' => false]);

        $withAllowance = $this->payslip('July')->fresh();

        $this->assertSame($taxBefore, round((float) $withAllowance->withholding_tax, 2), 'the tax is unmoved');
        $this->assertSame(470000.0, (float) $withAllowance->total_earnings, 'still paid, just not taxed');
    }

    public function test_switching_a_component_off_stops_paying_it(): void
    {
        $component = $this->addAllowance('fuel_card', 'Fuel Card', 12000);

        $this->assertSame(432000.0, (float) $this->payslip('July')->fresh()->total_earnings);

        $component->update(['is_active' => false]);

        $this->assertSame(420000.0, (float) $this->payslip('August')->fresh()->total_earnings);
    }

    /**
     * Refused where it is defined, not where it breaks.
     *
     * Left unmapped, the payslip pays the allowance and the journal entry has no debit
     * for it — so payroll fails with "debits 420,000 != credits 425,000" while somebody
     * is saving a payslip, pointing at nothing that explains it. The component is the
     * thing that is wrong, so the component refuses to be saved.
     */
    public function test_a_component_with_nowhere_to_post_is_refused(): void
    {
        $this->expectExceptionMessage('needs an account');

        PayComponent::create([
            'code' => 'unmapped',
            'label' => 'Unmapped Allowance',
            'kind' => PayComponent::KIND_EARNING,
        ]);
    }

    public function test_correcting_the_package_does_not_rewrite_an_earlier_payslip(): void
    {
        // What a payslip paid is a different fact from what somebody is due.
        $component = $this->addAllowance('fuel_card', 'Fuel Card', 12000);

        $july = $this->payslip('July')->fresh();
        $this->assertSame(432000.0, (float) $july->total_earnings);

        EmployeeSettingComponent::where('pay_component_id', $component->getKey())->update(['amount' => 20000]);

        $this->assertSame(432000.0, (float) $july->fresh()->total_earnings, 'July is untouched');
        $this->assertSame(
            12000.0,
            round((float) $july->components()->where('pay_component_id', $component->getKey())->value('amount'), 2),
            'and its record of what it paid is untouched too',
        );
    }
}
