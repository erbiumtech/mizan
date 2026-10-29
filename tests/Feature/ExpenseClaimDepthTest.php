<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Expenses\Notifications\ExpenseClaimSubmitted;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Claims with lines, a draft state and a chain — docs/saas-launch-plan.md §14.3.
 */
class ExpenseClaimDepthTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $accountant;

    private User $manager;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->actingAs($this->makeUser('Administrator', 'claims-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'expenses', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();
        $this->seed(\Database\Seeders\TransactionTypeSeeder::class);

        $this->accountant = $this->makeUser('Accountant', 'claims-acc@test.local');
        $this->manager = $this->makeUser('Manager', 'claims-mgr@test.local');
        $claimant = $this->makeUser('Employee', 'claims-emp@test.local');
        $this->employee = Employee::create(['user_id' => $claimant->id, 'employee_id' => 'EMP-C', 'name' => 'Nadeem', 'gender' => 'Male', 'is_active' => 1]);
    }

    private function claim(string $status = ExpenseClaim::STATUS_DRAFT): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $this->employee->id, 'claimed_on' => '2026-11-02', 'description' => 'Client visit', 'amount' => 0,
            'status' => $status, 'submitted_by' => $this->employee->user_id,
        ]);
    }

    public function test_the_lines_are_the_claim_and_a_draft_tells_nobody_until_submitted(): void
    {
        $claim = $this->claim();
        Notification::assertNothingSent();

        $claim->lines()->create(['spent_on' => '2026-11-03', 'description' => 'Taxi', 'amount' => 1500]);
        $claim->lines()->create(['spent_on' => '2026-11-02', 'description' => 'Lunch', 'amount' => 2200.5]);

        $this->assertSame(3700.5, (float) $claim->fresh()->amount);
        $this->assertSame('2026-11-02', $claim->fresh()->claimed_on->toDateString(), 'the earliest line');

        $claim->fresh()->submit();
        $this->assertTrue($claim->fresh()->isPending());
        $this->assertNotNull($claim->fresh()->submitted_at);
        Notification::assertSentTo($this->accountant, ExpenseClaimSubmitted::class);

        $this->expectException(InvalidArgumentException::class);
        $claim->fresh()->submit();
    }

    public function test_an_empty_draft_cannot_be_submitted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one line');
        $this->claim()->submit();
    }

    public function test_the_chain_is_the_manager_then_hr_and_each_step_is_recorded(): void
    {
        app(TenantSettings::class)->set('expenses.approval_chain', ['Manager']);
        $claim = $this->claim(ExpenseClaim::STATUS_PENDING);
        $claim->lines()->create(['spent_on' => '2026-11-03', 'description' => 'Taxi', 'amount' => 1500]);
        $claim = $claim->fresh();

        $this->assertStringContainsString('waiting for the first approval, then Manager', $claim->awaitingLabel());
        $this->assertTrue($this->accountant->can('decide', $claim));

        $claim->approve($this->accountant);
        $this->assertTrue($claim->fresh()->isPending(), 'one step of two');
        $this->assertSame(1, $claim->approvals()->count());
        $this->assertStringContainsString('waiting for Manager', $claim->fresh()->awaitingLabel());
        $this->assertFalse($this->accountant->can('decide', $claim->fresh()), 'the next step is not theirs');

        try {
            $claim->fresh()->approve($this->accountant);
            $this->fail('An accountant took the Manager step.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Manager', $e->getMessage());
        }

        $claim->fresh()->approve($this->manager);
        $this->assertTrue($claim->fresh()->isApproved());
        $this->assertSame(2, $claim->approvals()->count());
        $this->assertNotNull($claim->fresh()->journal_entry_id, 'the last step books the accrual');
    }

    public function test_without_a_chain_one_approval_books_the_lines_each_to_their_category(): void
    {
        $misc = TransactionType::byCode('miscellaneous');
        $category = ExpenseCategory::create(['name' => 'Travel', 'transaction_type_id' => $misc?->id]);

        $claim = $this->claim(ExpenseClaim::STATUS_PENDING);
        $claim->lines()->create(['spent_on' => '2026-11-03', 'expense_category_id' => $category->id, 'description' => 'Taxi', 'amount' => 1500]);
        $claim->lines()->create(['spent_on' => '2026-11-03', 'description' => 'Lunch', 'amount' => 500]);

        $claim->fresh()->approve($this->accountant);
        $entry = $claim->fresh()->journalEntry;

        $this->assertNotNull($entry);
        $this->assertSame(3, $entry->lines()->count(), 'a debit per line and one credit');
        $this->assertSame(2000.0, (float) $entry->lines()->whereNotNull('credit_amount')->where('credit_amount', '>', 0)->sum('credit_amount'));
    }
}
