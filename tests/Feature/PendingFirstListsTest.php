<?php

namespace Tests\Feature;

use App\Filament\Support\PendingFirst;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Modules\ConstructionCosting\Models\Requisition;
use App\Modules\ConstructionCosting\Services\RequisitionService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Filament\Resources\LetterRequests\Pages\ListLetterRequests;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\LetterRequest;
use App\Modules\Expenses\Filament\Resources\ExpenseClaims\Pages\ListExpenseClaims;
use App\Modules\Expenses\Models\ExpenseClaim;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Lists that hold records awaiting approval show those first, then the rest in the list's own order —
 * App\Filament\Support\PendingFirst. A header the reader clicks still decides the order.
 *
 * Statuses are written straight to the rows (no events) so each list gets a deliberate mix, created
 * out of order: the order on screen then comes from the sort and nothing else.
 */
class PendingFirstListsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'pending-first@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'expenses', 'accounting', 'construction', 'construction_costing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        Notification::fake();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'claimant@test.local')->id,
            'employee_id' => 'EMP-PF',
            'gender' => 'Male',
        ]);
    }

    public function test_pending_expense_claims_come_first_then_newest_spent(): void
    {
        $approvedLate = $this->claim('2026-10-20', 300, ExpenseClaim::STATUS_APPROVED);
        $pendingEarly = $this->claim('2026-10-01', 100, ExpenseClaim::STATUS_PENDING);
        $refused = $this->claim('2026-10-15', 400, ExpenseClaim::STATUS_REFUSED);
        $pendingLate = $this->claim('2026-10-10', 200, ExpenseClaim::STATUS_PENDING);

        Livewire::test(ListExpenseClaims::class)
            ->assertCanSeeTableRecords([$pendingLate, $pendingEarly, $approvedLate, $refused], inOrder: true);
    }

    public function test_a_clicked_header_still_decides_the_order(): void
    {
        $big = $this->claim('2026-10-20', 900, ExpenseClaim::STATUS_APPROVED);
        $small = $this->claim('2026-10-01', 100, ExpenseClaim::STATUS_REFUSED);
        $middle = $this->claim('2026-10-10', 500, ExpenseClaim::STATUS_PENDING);

        Livewire::test(ListExpenseClaims::class)
            ->sortTable('amount')
            ->assertCanSeeTableRecords([$small, $middle, $big], inOrder: true)
            ->sortTable('amount', 'desc')
            ->assertCanSeeTableRecords([$big, $middle, $small], inOrder: true);
    }

    public function test_pending_letter_requests_come_first_then_newest(): void
    {
        $issued = $this->letter(LetterRequest::STATUS_ISSUED);
        $pendingOld = $this->letter(LetterRequest::STATUS_PENDING);
        $refused = $this->letter(LetterRequest::STATUS_REFUSED);
        $pendingNew = $this->letter(LetterRequest::STATUS_PENDING);

        Livewire::test(ListLetterRequests::class)
            ->assertCanSeeTableRecords([$pendingNew, $pendingOld, $refused, $issued], inOrder: true);
    }

    public function test_submitted_requisitions_come_first_then_newest(): void
    {
        $job = Job::create(['code' => 'J-PF', 'name' => 'Tower']);
        $service = app(RequisitionService::class);

        $make = function (string $status) use ($job, $service): Requisition {
            $requisition = $service->create($job, ['required_by' => '2026-11-15']);
            Requisition::query()->whereKey($requisition->getKey())->update(['status' => $status]);

            return $requisition->refresh();
        };

        $submittedOld = $make(Requisition::STATUS_SUBMITTED);
        $draft = $make(Requisition::STATUS_DRAFT);
        $approved = $make(Requisition::STATUS_APPROVED);
        $submittedNew = $make(Requisition::STATUS_SUBMITTED);

        Livewire::test(ListRequisitions::class)
            ->assertCanSeeTableRecords([$submittedNew, $submittedOld, $approved, $draft], inOrder: true);
    }

    /** The list and the record agree on what "awaiting approval" is, because they read one declaration. */
    public function test_the_declaration_names_what_is_pending(): void
    {
        $this->assertTrue(PendingFirst::isPending($this->claim('2026-10-01', 100, ExpenseClaim::STATUS_PENDING)));
        $this->assertFalse(PendingFirst::isPending($this->claim('2026-10-01', 100, ExpenseClaim::STATUS_APPROVED)));
    }

    private function claim(string $spent, float $amount, string $status): ExpenseClaim
    {
        $claim = ExpenseClaim::create([
            'employee_id' => $this->employee->getKey(),
            'claimed_on' => $spent,
            'description' => "Claim of {$amount}",
            'amount' => $amount,
            'submitted_by' => $this->employee->user_id,
        ]);

        ExpenseClaim::query()->whereKey($claim->getKey())->update(['status' => $status]);

        return $claim->refresh();
    }

    private function letter(string $status): LetterRequest
    {
        $request = LetterRequest::create(['employee_id' => $this->employee->getKey(), 'kind' => 'noc']);

        LetterRequest::query()->whereKey($request->getKey())->update(['status' => $status]);

        return $request->refresh();
    }
}
