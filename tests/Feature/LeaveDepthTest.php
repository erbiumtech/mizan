<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Filament\Pages\LeaveCalendar;
use App\Modules\Leave\Models\LeaveAdjustment;
use App\Modules\Leave\Models\LeaveEncashment;
use App\Modules\Leave\Models\LeaveGroup;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalance;
use App\Modules\Leave\Services\LeaveEncashments;
use App\Modules\Leave\Services\LeaveEntitlementService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Payroll\Models\PayAdjustment;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Leave groups, eligibility, encashment and the calendar — docs/saas-launch-plan.md §14.4.
 */
class LeaveDepthTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $ayesha;

    private Employee $bilal;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        Notification::fake();
        $this->actingAs($this->makeUser('Administrator', 'leave-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'leave', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();
        $this->seed([\Database\Seeders\TransactionTypeSeeder::class, \Database\Seeders\PayComponentSeeder::class]);

        $this->ayesha = Employee::create(['user_id' => $this->makeUser('Employee', 'ayesha-l@test.local')->id, 'employee_id' => 'EMP-1', 'name' => 'Ayesha', 'gender' => 'Female', 'is_active' => 1, 'date_of_joining' => '2020-01-01', 'employment_type' => 'permanent']);
        $this->bilal = Employee::create(['user_id' => $this->makeUser('Employee', 'bilal-l@test.local')->id, 'employee_id' => 'EMP-2', 'name' => 'Bilal', 'gender' => 'Male', 'is_active' => 1, 'date_of_joining' => '2026-09-01', 'employment_type' => 'contract']);
        $this->annual = LeaveType::create(['code' => 'annual', 'label' => 'Annual Leave', 'days_per_year' => 14, 'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT, 'is_encashable' => true, 'colour' => '#2563eb']);
    }

    public function test_eligibility_reads_gender_service_employment_type_and_a_run_of_days(): void
    {
        $maternity = LeaveType::create(['code' => 'maternity', 'label' => 'Maternity', 'days_per_year' => 90, 'eligible_genders' => ['Female']]);
        $this->assertNull($maternity->eligibilityProblem($this->ayesha));
        $this->assertStringContainsString('for Female employees', $maternity->eligibilityProblem($this->bilal));

        $hajj = LeaveType::create(['code' => 'hajj', 'label' => 'Hajj', 'days_per_year' => 30, 'min_service_months' => 12, 'max_consecutive_days' => 30]);
        $this->assertNull($hajj->eligibilityProblem($this->ayesha, 30));
        $this->assertStringContainsString('needs 12 months of service; 2 so far', $hajj->eligibilityProblem($this->bilal));
        $this->assertStringContainsString('at most 30 consecutive days', $hajj->eligibilityProblem($this->ayesha, 31));

        $permanentOnly = LeaveType::create(['code' => 'study', 'label' => 'Study', 'days_per_year' => 5, 'eligible_employment_types' => ['permanent']]);
        $this->assertStringContainsString('for permanent staff', $permanentOnly->eligibilityProblem($this->bilal));

        (new LeaveTypeSeeder)->run();
        $this->assertSame(12, LeaveType::where('code', 'umrah')->first()->min_service_months);
        $this->assertSame(7, LeaveType::where('code', 'marriage')->first()->max_consecutive_days);
    }

    public function test_a_group_decides_what_is_offered_opened_and_accepted(): void
    {
        $casual = LeaveType::create(['code' => 'casual', 'label' => 'Casual Leave', 'days_per_year' => 10, 'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT]);
        $this->assertSame(['annual', 'casual'], LeaveGroup::typesFor($this->bilal)->pluck('code')->sort()->values()->all(), 'no group at all: every active type');

        $contract = LeaveGroup::create(['name' => 'Contract staff']);
        $contract->types()->sync([$casual->getKey()]);
        $this->bilal->update(['leave_group_id' => $contract->getKey()]);
        $this->assertSame(['casual'], LeaveGroup::typesFor($this->bilal->fresh())->pluck('code')->all());

        LeaveGroup::create(['name' => 'Everyone else', 'is_default' => true])->types()->sync([$this->annual->getKey(), $casual->getKey()]);
        $this->assertSame(['annual', 'casual'], LeaveGroup::typesFor($this->ayesha)->pluck('code')->sort()->values()->all(), 'the default group');

        app(LeaveEntitlementService::class)->openYearFor();
        $this->assertSame(2, \App\Modules\Leave\Models\LeaveEntitlement::query()->where('employee_id', $this->ayesha->getKey())->count(), 'opened for the default group\'s two types');
        $this->assertSame(1, \App\Modules\Leave\Models\LeaveEntitlement::query()->where('employee_id', $this->bilal->getKey())->count(), 'one type for contract staff');

        $request = new LeaveRequest(['employee_id' => $this->bilal->getKey(), 'leave_type_id' => $this->annual->getKey(), 'from_date' => '2026-11-10', 'to_date' => '2026-11-10', 'reason' => 'x']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("not in this employee's leave group");
        app(LeaveRequestService::class)->submit($request, $this->bilal->user);
    }

    public function test_an_encashment_debits_the_balance_and_reaches_the_payslip(): void
    {
        app(LeaveEntitlementService::class)->open($this->ayesha, $this->annual);
        $before = app(LeaveBalance::class)->remaining($this->ayesha, $this->annual);
        $this->assertSame(14.0, $before);

        $encashment = LeaveEncashment::create(['employee_id' => $this->ayesha->getKey(), 'leave_type_id' => $this->annual->getKey(), 'days' => 5, 'amount' => 25000, 'month' => 'November', 'fiscal_year_id' => $this->fiscalYear->getKey(), 'reason' => 'Eid']);

        app(LeaveEncashments::class)->approve($encashment, auth()->user());

        $this->assertSame(LeaveEncashment::STATUS_APPROVED, $encashment->fresh()->status);
        $this->assertSame(9.0, app(LeaveBalance::class)->remaining($this->ayesha, $this->annual));
        $this->assertSame(-5.0, (float) LeaveAdjustment::sole()->days);
        $this->assertStringContainsString('Encashed', LeaveAdjustment::sole()->reason);

        $input = PayAdjustment::sole();
        $this->assertSame(25000.0, (float) $input->amount);
        $this->assertSame('November', $input->month);
        $this->assertSame('bonus', $input->component->code);
        $this->assertStringContainsString('Leave encashment: 5.0 day(s)', $input->reason);

        $tooMany = LeaveEncashment::create(['employee_id' => $this->ayesha->getKey(), 'leave_type_id' => $this->annual->getKey(), 'days' => 20, 'amount' => 1]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only 9.0 day(s)');
        app(LeaveEncashments::class)->approve($tooMany, auth()->user());
    }

    public function test_the_calendar_shows_approved_days_in_the_types_colour(): void
    {
        app(LeaveEntitlementService::class)->open($this->ayesha, $this->annual);
        $request = new LeaveRequest(['employee_id' => $this->ayesha->getKey(), 'leave_type_id' => $this->annual->getKey(), 'from_date' => '2026-11-10', 'to_date' => '2026-11-11', 'reason' => 'Wedding']);
        app(LeaveRequestService::class)->submit($request, $this->ayesha->user);
        app(LeaveRequestService::class)->approve($request->fresh(), auth()->user());

        $page = new LeaveCalendar;
        $page->month = '2026-11';
        $grid = $page->grid();

        $this->assertCount(30, $grid['days']);
        $this->assertCount(1, $grid['rows']);
        $this->assertSame('#2563eb', $grid['rows'][0]['cells']['2026-11-10']['colour']);
        $this->assertSame('AN', $grid['rows'][0]['cells']['2026-11-11']['label']);
        $this->assertArrayNotHasKey('2026-11-12', $grid['rows'][0]['cells']);
    }
}
