<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\WorkPattern;
use App\Modules\Attendance\Services\WorkPatternResolver;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Leave\Models\LeaveDay;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\AttendanceFigures;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The two payroll-column guarantees the plan asked for and nothing asserted:
 * docs/hrms-plan.md **§10.7** and **§10.17**.
 *
 * §10.7 is the convention these four columns spent their whole life not having. They were
 * typed by hand and read by nothing, so `leaves_taken` and `lop_days` meant whatever the
 * clerk assumed — §11 settled it, and this is where the settlement is enforced:
 *
 *   `leaves_taken`  approved PAID leave. Costs nothing.
 *   `lop_days`      UNPAID absence. The only column pro-rating may ever read.
 *
 * §10.17 is subtler and needed a column to be keepable at all: leave approved for a month
 * that has already been signed off must land in the NEXT month rather than being lost or
 * counted every month for ever. `leave_days.settled_payslip_id` is what makes that
 * checkable.
 */
class PayrollLeaveColumnsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Employee $employee;

    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['leave', 'attendance', 'employees', 'payroll'] as $module) {
            $this->setModule($module, true);
        }

        // Accounting OFF, deliberately. Opening a month creates payslips, and a payslip
        // posts to the ledger — which needs a seeded chart of accounts this test has no
        // interest in. PayrollPostingService returns early when accounting is unavailable,
        // so this exercises the guarded path and keeps the test about the four columns.
        $this->setModule('accounting', false);

        $this->fiscalYear = FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30'],
        );

        $this->employee = Employee::create([
            'employee_id' => 'EMP-1',
            'name' => 'Ali Raza',
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
        ]);

        $this->makeFiveDayPattern();
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function makeFiveDayPattern(): void
    {
        $pattern = WorkPattern::create(['name' => 'Mon-Fri', 'is_default' => true]);

        foreach (range(1, 7) as $weekday) {
            $pattern->days()->create([
                'weekday' => $weekday,
                'is_working' => $weekday <= 5,
                'expected_hours' => $weekday <= 5 ? 8 : null,
            ]);
        }

        app(WorkPatternResolver::class)->flush();
    }

    private function type(bool $paid): LeaveType
    {
        return LeaveType::create([
            'code' => $paid ? 'annual' : 'unpaid',
            'label' => $paid ? 'Annual Leave' : 'Unpaid Leave',
            'is_paid' => $paid,
            'days_per_year' => $paid ? 14 : null,
            'accrual_method' => $paid ? LeaveType::ACCRUAL_ANNUAL_UPFRONT : LeaveType::ACCRUAL_NONE,
        ]);
    }

    private function approveLeave(LeaveType $type, string $from, string $to): LeaveRequest
    {
        $request = app(LeaveRequestService::class)->submit(
            new LeaveRequest([
                'employee_id' => $this->employee->id,
                'leave_type_id' => $type->id,
                'from_date' => $from,
                'to_date' => $to,
            ]),
            $this->actor,
        );

        app(LeaveRequestService::class)->approve($request, User::factory()->create(['status' => 1]));

        return $request->fresh();
    }

    // ──────────────────────────────── §10.7 ────────────────────────────────

    /**
     * THE §10.7 test: paid leave never writes `lop_days`, unpaid never writes
     * `leaves_taken`.
     *
     * The two columns meant the same thing for as long as nothing read them. This is what
     * keeps them apart now that something does — and it matters because only one of them
     * can ever reduce somebody's pay.
     */
    public function test_paid_leave_writes_leaves_taken_and_never_lop_days(): void
    {
        // 12-14 August 2026 are Wed-Fri: three working days.
        $this->approveLeave($this->type(paid: true), '2026-08-12', '2026-08-14');

        $figures = app(AttendanceFigures::class)->for($this->employee, 'August', $this->fiscalYear);

        $this->assertSame(3.0, $figures['leaves_taken']);
        $this->assertSame(0.0, $figures['lop_days'], 'A paid day is paid: it can never be loss of pay.');
    }

    public function test_unpaid_leave_writes_lop_days_and_never_leaves_taken(): void
    {
        $this->approveLeave($this->type(paid: false), '2026-08-12', '2026-08-14');

        $figures = app(AttendanceFigures::class)->for($this->employee, 'August', $this->fiscalYear);

        $this->assertSame(3.0, $figures['lop_days']);
        $this->assertSame(0.0, $figures['leaves_taken'], 'Unpaid leave is not leave taken against a balance.');
    }

    /** Both kinds in one month land in their own columns and do not bleed. */
    public function test_paid_and_unpaid_leave_in_one_month_stay_in_their_own_columns(): void
    {
        $this->approveLeave($this->type(paid: true), '2026-08-12', '2026-08-13');
        $this->approveLeave($this->type(paid: false), '2026-08-19', '2026-08-20');

        $figures = app(AttendanceFigures::class)->for($this->employee, 'August', $this->fiscalYear);

        $this->assertSame(2.0, $figures['leaves_taken']);
        $this->assertSame(2.0, $figures['lop_days']);
        // 21 working days in August 2026, less the two unpaid.
        $this->assertSame(21.0, $figures['total_working_days']);
        $this->assertSame(19.0, $figures['paid_days']);
    }

    /**
     * The `is_paid` recorded on the DAY decides, not the type as it reads today.
     *
     * Correcting a type must not restate a month already settled — the same rule the
     * proration divisor and the overtime rate both follow.
     */
    public function test_correcting_a_leave_type_does_not_restate_days_already_generated(): void
    {
        $type = $this->type(paid: true);
        $this->approveLeave($type, '2026-08-12', '2026-08-14');

        // HR decides this type should have been unpaid all along.
        $type->update(['is_paid' => false]);

        $figures = app(AttendanceFigures::class)->for($this->employee, 'August', $this->fiscalYear);

        $this->assertSame(3.0, $figures['leaves_taken'], 'The days were generated as paid and stay paid.');
        $this->assertSame(0.0, $figures['lop_days']);
    }

    /** A half day of unpaid leave is half a day of loss of pay, not a whole one. */
    public function test_a_half_day_of_unpaid_leave_is_half_a_day_of_loss_of_pay(): void
    {
        $type = $this->type(paid: false);

        $request = app(LeaveRequestService::class)->submit(
            new LeaveRequest([
                'employee_id' => $this->employee->id,
                'leave_type_id' => $type->id,
                'from_date' => '2026-08-12',
                'to_date' => '2026-08-12',
                'is_half_day' => true,
                'half_day_period' => LeaveRequest::HALF_FIRST,
            ]),
            $this->actor,
        );
        app(LeaveRequestService::class)->approve($request, User::factory()->create(['status' => 1]));

        $this->assertSame(0.5, app(AttendanceFigures::class)->for($this->employee, 'August', $this->fiscalYear)['lop_days']);
    }

    // ──────────────────────────────── §10.17 ───────────────────────────────

    /**
     * THE §10.17 test: leave approved after sign-off lands in the NEXT month.
     *
     * July is locked, and leave for 15 July is approved afterwards. It must not vanish (the
     * July payslip is closed) and must not be counted every month for ever. It appears
     * exactly once, in August.
     */
    public function test_leave_approved_after_a_lock_lands_in_the_next_month(): void
    {
        $type = $this->type(paid: false);

        // July is run and signed off.
        app(MonthlyPayrollService::class)->openMonth('July', $this->fiscalYear);
        PayrollRun::forMonth('July', $this->fiscalYear)->update(['status' => PayrollRun::STATUS_LOCKED]);

        // Only now is July's leave approved.
        $this->approveLeave($type, '2026-07-15', '2026-07-16');

        // July's own figures no longer take it — that month is closed.
        // August's do.
        $august = app(AttendanceFigures::class)->for($this->employee, 'August', $this->fiscalYear);
        $this->assertSame(2.0, $august['lop_days'], 'Leave from a closed month carries into the open one.');

        // Counted once: after August settles it, September sees nothing.
        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);

        $september = app(AttendanceFigures::class)->for($this->employee, 'September', $this->fiscalYear);
        $this->assertSame(0.0, $september['lop_days'], 'A day already settled must never be counted again.');
    }

    /**
     * Leave in an OPEN earlier month is left alone.
     *
     * That month's payslip can still pick the days up itself, and pulling them forward
     * would take them off a payslip somebody is about to finish.
     */
    public function test_leave_in_an_earlier_open_month_is_not_pulled_forward(): void
    {
        $this->approveLeave($this->type(paid: false), '2026-07-15', '2026-07-16');

        $august = app(AttendanceFigures::class)->for($this->employee, 'August', $this->fiscalYear);

        $this->assertSame(0.0, $august['lop_days'], 'July is still open and keeps its own leave.');
        $this->assertSame(2.0, app(AttendanceFigures::class)->for($this->employee, 'July', $this->fiscalYear)['lop_days']);
    }

    /** Opening a month claims its days, so re-reading the figures cannot double them. */
    public function test_a_settled_day_is_not_counted_by_a_later_month(): void
    {
        $this->approveLeave($this->type(paid: false), '2026-08-12', '2026-08-13');

        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);

        $this->assertSame(2, LeaveDay::whereNotNull('settled_payslip_id')->count());
        $this->assertSame(
            0.0,
            app(AttendanceFigures::class)->for($this->employee, 'September', $this->fiscalYear)['lop_days'],
        );
    }

    /**
     * Deleting a payslip returns its leave days to the pool.
     *
     * nullOnDelete rather than cascade: a payroll correction must not delete somebody's
     * leave record, and the days have to be countable again by whatever replaces it.
     */
    public function test_deleting_a_payslip_returns_its_leave_days_to_the_pool(): void
    {
        $this->approveLeave($this->type(paid: false), '2026-08-12', '2026-08-13');

        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);
        $this->assertSame(0, LeaveDay::whereNull('settled_payslip_id')->count());

        Payslip::where('month', 'August')->delete();

        $this->assertSame(2, LeaveDay::whereNull('settled_payslip_id')->count());
    }

    /** Reading the figures never claims anything — only writing a payslip does. */
    public function test_reading_the_figures_does_not_settle_anything(): void
    {
        $this->approveLeave($this->type(paid: false), '2026-08-12', '2026-08-13');

        app(AttendanceFigures::class)->for($this->employee, 'August', $this->fiscalYear);
        app(AttendanceFigures::class)->for($this->employee, 'August', $this->fiscalYear);

        $this->assertSame(
            2,
            LeaveDay::whereNull('settled_payslip_id')->count(),
            'A read that claimed days would burn them for whoever merely looked at a form.',
        );
    }
}
