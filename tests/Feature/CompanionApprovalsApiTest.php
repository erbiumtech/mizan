<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A manager's approvals from the phone — docs/desktop-mobile-plan.md A4/M3.
 *
 * The list shows what the caller may decide and nothing else; approving and refusing go through the same
 * service and model methods the panel's buttons call, so the API inherits every rule they hold (a reason on
 * refusal, nobody approves their own); and all of it lives on /v1 only.
 */
class CompanionApprovalsApiTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $approver;

    private User $employeeUser;

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->approver = $this->makeUser('Administrator', 'approvals-admin@test.local');
        $this->actingAs($this->approver);
        $company = $this->setCurrentTenant();

        foreach (['employees', 'leave', 'expenses', 'payroll'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->employeeUser = $this->makeUser('Employee', 'approvals-employee@test.local');
        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'name' => 'Sana Iqbal',
            'employee_id' => 'EMP-APR',
            'gender' => 'Female',
            'phone' => '0300-0000020',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);

        $this->annual = LeaveType::create([
            'code' => 'annual', 'label' => 'Annual Leave', 'days_per_year' => 14,
            'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT,
        ]);
        LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'leave_year_start' => Carbon::now()->startOfYear()->toDateString(),
            'leave_year_end' => Carbon::now()->endOfYear()->toDateString(),
            'accrued_days' => 14,
        ]);
    }

    /** Monday and Tuesday of the given ISO week this year: two working days, whatever the weekend setting. */
    private function weekdays(int $week): array
    {
        $monday = Carbon::now()->startOfYear()->addWeeks($week)->next(Carbon::MONDAY);

        return [$monday->toDateString(), $monday->copy()->addDay()->toDateString()];
    }

    private function pendingLeave(int $week = 10): LeaveRequest
    {
        [$from, $to] = $this->weekdays($week);

        return app(LeaveRequestService::class)->submit(new LeaveRequest([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'from_date' => $from,
            'to_date' => $to,
            'reason' => 'Family wedding',
        ]), $this->employeeUser);
    }

    private function pendingClaim(): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $this->employee->id,
            'claimed_on' => '2026-08-02',
            'description' => 'Taxi to the client',
            'amount' => 1500,
            'submitted_by' => $this->employeeUser->id,
        ]);
    }

    // ---- Leave --------------------------------------------------------------

    public function test_a_manager_lists_and_approves_pending_leave_on_v1(): void
    {
        $leave = $this->pendingLeave();
        Sanctum::actingAs($this->approver, ['api']);

        $this->getJson('/api/v1/approvals/leave')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $leave->getKey())
            ->assertJsonPath('data.0.employee.name', 'Sana Iqbal')
            ->assertJsonPath('data.0.leave_type', 'Annual Leave')
            ->assertJsonPath('data.0.days', 2)
            ->assertJsonPath('data.0.status', 'pending');

        $this->postJson('/api/v1/approvals/leave/'.$leave->getKey().'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(2, $leave->fresh()->days()->count());
        $this->getJson('/api/v1/approvals/leave')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_refusing_leave_needs_a_reason_and_records_it(): void
    {
        $leave = $this->pendingLeave();
        Sanctum::actingAs($this->approver, ['api']);

        $this->postJson('/api/v1/approvals/leave/'.$leave->getKey().'/refuse', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->postJson('/api/v1/approvals/leave/'.$leave->getKey().'/refuse', ['reason' => 'Month-end close'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refused')
            ->assertJsonPath('data.refusal_reason', 'Month-end close');
    }

    public function test_an_employee_sees_no_approvals_and_cannot_decide(): void
    {
        $leave = $this->pendingLeave();
        Sanctum::actingAs($this->employeeUser, ['api']);

        $this->getJson('/api/v1/approvals/leave')->assertOk()->assertJsonPath('count', 0);
        $this->postJson('/api/v1/approvals/leave/'.$leave->getKey().'/approve')->assertForbidden();

        $this->assertTrue($leave->fresh()->isPending());
    }

    public function test_the_approvals_live_on_the_versioned_surface_only(): void
    {
        Sanctum::actingAs($this->approver, ['api']);

        $this->getJson('/api/approvals/leave')->assertNotFound();
        $this->getJson('/api/approvals/expense-claims')->assertNotFound();
    }

    // ---- Expense claims -------------------------------------------------------

    public function test_a_manager_lists_and_approves_pending_claims_on_v1(): void
    {
        $claim = $this->pendingClaim();
        Sanctum::actingAs($this->approver, ['api']);

        $this->getJson('/api/v1/approvals/expense-claims')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $claim->getKey())
            ->assertJsonPath('data.0.employee.name', 'Sana Iqbal')
            ->assertJsonPath('data.0.amount', 1500)
            ->assertJsonPath('data.0.description', 'Taxi to the client');

        $this->postJson('/api/v1/approvals/expense-claims/'.$claim->getKey().'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->getJson('/api/v1/approvals/expense-claims')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_refusing_a_claim_carries_its_reason(): void
    {
        $claim = $this->pendingClaim();
        Sanctum::actingAs($this->approver, ['api']);

        $this->postJson('/api/v1/approvals/expense-claims/'.$claim->getKey().'/refuse', ['reason' => 'No receipt'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refused')
            ->assertJsonPath('data.refusal_reason', 'No receipt');
    }

    public function test_nobody_approves_their_own_claim_over_the_api(): void
    {
        // A claim the approver filed for the employee: the policy refuses the person who submitted it.
        $claim = ExpenseClaim::create([
            'employee_id' => $this->employee->id,
            'claimed_on' => '2026-08-03',
            'description' => 'Filed by the approver',
            'amount' => 900,
            'submitted_by' => $this->approver->id,
        ]);
        Sanctum::actingAs($this->approver, ['api']);

        $this->getJson('/api/v1/approvals/expense-claims')->assertOk()->assertJsonPath('count', 0);
        $this->postJson('/api/v1/approvals/expense-claims/'.$claim->getKey().'/approve')->assertForbidden();
    }
}
