<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Models\ExpenseClaim;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An expense category's "Needs a receipt" is enforced when the claim goes to the approvers.
 *
 * The switch was stored and shown and never checked: a line in a needs-a-receipt category was submitted with
 * nothing attached. A draft may still be waiting for the receipt; submitting it may not.
 */
class ExpenseReceiptRequiredTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->actingAs($this->makeUser('Administrator', 'receipts@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'expenses'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'receipts-emp@test.local')->id, 'employee_id' => 'EMP-RC', 'gender' => 'Female',
        ]);
    }

    private function draftWith(ExpenseCategory $category, ?string $receipt): ExpenseClaim
    {
        $claim = ExpenseClaim::create([
            'employee_id' => $this->employee->id, 'claimed_on' => '2026-10-01', 'description' => 'Client visit',
            'amount' => 0, 'status' => ExpenseClaim::STATUS_DRAFT, 'submitted_by' => auth()->id(),
        ]);
        $claim->lines()->create(['spent_on' => '2026-10-01', 'expense_category_id' => $category->id, 'description' => 'Taxi', 'amount' => 2500, 'receipt_path' => $receipt]);

        return $claim->fresh();
    }

    public function test_a_line_that_needs_a_receipt_stops_the_submit(): void
    {
        $claim = $this->draftWith(ExpenseCategory::create(['name' => 'Travel', 'requires_receipt' => true]), null);

        try {
            $claim->submit();
            $this->fail('A claim with a missing receipt was submitted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Taxi (Travel)', $e->getMessage());
        }

        $this->assertSame(ExpenseClaim::STATUS_DRAFT, $claim->fresh()->status);
    }

    public function test_with_the_receipt_attached_it_submits(): void
    {
        $claim = $this->draftWith(ExpenseCategory::create(['name' => 'Travel', 'requires_receipt' => true]), 'expense-claims/taxi.jpg');

        $claim->submit();

        $this->assertSame(ExpenseClaim::STATUS_PENDING, $claim->fresh()->status);
    }

    public function test_a_category_that_does_not_need_one_submits_without(): void
    {
        $claim = $this->draftWith(ExpenseCategory::create(['name' => 'Meals', 'requires_receipt' => false]), null);

        $claim->submit();

        $this->assertSame(ExpenseClaim::STATUS_PENDING, $claim->fresh()->status);
    }
}
