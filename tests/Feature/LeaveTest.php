<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalance;
use App\Modules\Leave\Services\LeaveEntitlementService;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveYear;
use App\Support\TenantSettings;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The `leave` module — docs/hrms-plan.md §4.1 and the §10 cases that reach phase 1.
 *
 * The through-line of this file is one rule, stated once in §4.7 and tested here per
 * setting that could break it: **a setting decides what happens next, never what
 * already happened.** Every one of `year_basis`, `carry_forward`,
 * `prorate_first_year` and `sandwich_rule` could restate a settled figure if
 * implemented as a read-time derivation, and each has a test below that would fail if
 * somebody made it one.
 */
class LeaveTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        $this->employee = $this->makeEmployee('EMP-1', ['date_of_joining' => '2020-01-01']);
    }

    private function makeEmployee(string $employeeId, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => $employeeId,
            'name' => $employeeId,
            'gender' => 'Male',
            'is_active' => true,
        ], $attributes));
    }

    private function makeType(array $attributes = []): LeaveType
    {
        return LeaveType::create(array_merge([
            'code' => 'annual',
            'label' => 'Annual Leave',
            'days_per_year' => 14,
            'accrual_method' => LeaveType::ACCRUAL_ANNUAL_UPFRONT,
        ], $attributes));
    }

    private function set(string $key, mixed $value): void
    {
        app(TenantSettings::class)->set($key, $value);
    }

    private function requestFor(LeaveType $type, string $from, string $to, array $attributes = []): LeaveRequest
    {
        return app(LeaveRequestService::class)->submit(
            new LeaveRequest(array_merge([
                'employee_id' => $this->employee->id,
                'leave_type_id' => $type->id,
                'from_date' => $from,
                'to_date' => $to,
            ], $attributes)),
            $this->actor,
        );
    }

    /** An approver who is somebody else, since the default rule requires one. */
    private function approver(): User
    {
        return User::factory()->create(['status' => 1]);
    }

    // ---------------------------------------------------------------- day generation

    /**
     * §10.1 — THE test for leave_days. Everything else about the table follows from it.
     *
     * A request from Friday to the following Tuesday spans a weekend and a public
     * holiday. Its *range* is five days; what it consumes is two, and only per-day
     * rows can say so. A `days` total on the request could not.
     */
    public function test_a_request_skips_weekends_and_holidays(): void
    {
        $type = $this->makeType();

        // 2026-08-14 is a Friday; 15/16 the weekend; 17 August a holiday; 18 Tuesday.
        Holiday::create(['date' => '2026-08-17', 'name' => 'Independence Day (observed)']);

        $request = $this->requestFor($type, '2026-08-14', '2026-08-18');
        app(LeaveRequestService::class)->approve($request, $this->approver());

        $dates = $request->fresh()->days()->orderBy('date')->pluck('date')
            ->map(fn ($date) => $date->toDateString())->all();

        $this->assertSame(['2026-08-14', '2026-08-18'], $dates);
        $this->assertSame(2.0, (float) $request->fresh()->days);
    }

    /**
     * §10.2 — the reason payroll needs rows rather than a total.
     *
     * A leave from 28 January to 3 February has to reach two payslips. Splitting it
     * is only possible because each day is a row that knows its own month.
     */
    public function test_a_request_spanning_a_month_boundary_splits_by_month(): void
    {
        $type = $this->makeType();

        $request = $this->requestFor($type, '2026-01-28', '2026-02-03');
        app(LeaveRequestService::class)->approve($request, $this->approver());
        $request = $request->fresh();

        // Jan 28, 29, 30 are Wed–Fri; 31/1 the weekend; Feb 2, 3 Mon–Tue.
        $this->assertSame(3.0, $request->daysInMonth(2026, 1));
        $this->assertSame(2.0, $request->daysInMonth(2026, 2));
        $this->assertSame(5.0, (float) $request->days);
    }

    /** A half day costs half a day, and only on a single date. */
    public function test_a_half_day_consumes_half_a_day(): void
    {
        $type = $this->makeType();

        $request = $this->requestFor($type, '2026-08-14', '2026-08-14', [
            'is_half_day' => true,
            'half_day_period' => LeaveRequest::HALF_FIRST,
        ]);
        app(LeaveRequestService::class)->approve($request, $this->approver());

        $this->assertSame(0.5, (float) $request->fresh()->days);
        $this->assertSame(0.5, (float) $request->fresh()->days()->sum('portion'));
    }

    /** A range that is entirely non-working consumes nothing, so it is refused outright. */
    public function test_a_request_covering_no_working_day_is_refused(): void
    {
        $type = $this->makeType();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('covers no working days');

        // 2026-08-15 and 16 are Saturday and Sunday.
        $this->requestFor($type, '2026-08-15', '2026-08-16');
    }

    // ---------------------------------------------------------------- sandwich rule

    /**
     * §10.13b, first half — both directions, because it is a setting.
     *
     * Friday plus Monday: two days with the rule off, four with it on. The rule only
     * ever consumes days *between* two leave days.
     */
    public function test_the_sandwich_rule_counts_enclosed_non_working_days(): void
    {
        $type = $this->makeType();

        $off = $this->requestFor($type, '2026-08-14', '2026-08-17');
        app(LeaveRequestService::class)->approve($off, $this->approver());
        $this->assertSame(2.0, (float) $off->fresh()->days, 'With the rule off, the weekend is skipped.');
        $this->assertFalse((bool) $off->fresh()->sandwich_rule_applied);

        $this->set('leave.sandwich_rule', 'enclosed');

        $this->employee = $this->makeEmployee('EMP-2', ['date_of_joining' => '2020-01-01']);
        $on = $this->requestFor($type, '2026-08-14', '2026-08-17');
        app(LeaveRequestService::class)->approve($on, $this->approver());

        $this->assertSame(4.0, (float) $on->fresh()->days, 'With the rule on, the enclosed weekend is consumed.');
        $this->assertTrue((bool) $on->fresh()->sandwich_rule_applied);
    }

    /**
     * The variant deliberately NOT built: a single Friday never costs three days.
     *
     * `enclosed` means between two leave days. The stricter reading some policies
     * take — a holiday adjacent to leave consumed at the edges too — makes one day
     * off cost three, which nobody expects.
     */
    public function test_the_sandwich_rule_never_consumes_the_edges(): void
    {
        $type = $this->makeType();
        $this->set('leave.sandwich_rule', 'enclosed');

        $request = $this->requestFor($type, '2026-08-14', '2026-08-14');
        app(LeaveRequestService::class)->approve($request, $this->approver());

        $this->assertSame(1.0, (float) $request->fresh()->days);
    }

    /**
     * §10.13b, second half, and §10.8's rule applied to the setting most able to
     * break it: switching the sandwich rule on must not restate leave already taken.
     */
    public function test_switching_the_sandwich_rule_on_does_not_restate_an_approved_request(): void
    {
        $type = $this->makeType();

        $request = $this->requestFor($type, '2026-08-14', '2026-08-17');
        app(LeaveRequestService::class)->approve($request, $this->approver());
        $this->assertSame(2.0, (float) $request->fresh()->days);

        $this->set('leave.sandwich_rule', 'enclosed');

        // Nothing regenerates. The days were decided at approval and stay decided.
        $this->assertSame(2.0, (float) $request->fresh()->days);
        $this->assertSame(2, $request->fresh()->days()->count());
    }

    // ---------------------------------------------------------------- balances

    /**
     * §10.3 — the balance is arithmetic over rows, not a stored column.
     *
     * Asserted against leave_days rather than a `balance` field, which is the point:
     * there is no such field, and a test that read one would pass while the number
     * drifted.
     */
    public function test_the_balance_is_credits_less_the_days_actually_taken(): void
    {
        $type = $this->makeType(['days_per_year' => 14]);

        $entitlement = LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'leave_year_start' => '2026-01-01',
            'leave_year_end' => '2026-12-31',
            'opening_days' => 2,
            'accrued_days' => 14,
            'carried_in_days' => 1,
        ]);

        $entitlement->adjustments()->create(['days' => 3, 'reason' => 'Goodwill for the March release']);
        $entitlement->adjustments()->create(['days' => -1, 'reason' => 'Correction: double-counted a day']);

        $request = $this->requestFor($type, '2026-08-14', '2026-08-18');
        app(LeaveRequestService::class)->approve($request, $this->approver());

        $breakdown = app(LeaveBalance::class)->for($this->employee, $type, '2026-08-14');

        // 2 opening + 1 carried + 14 accrued + (3 − 1) adjustments = 19 credited.
        $this->assertSame(19.0, $breakdown->credited());
        // 14 Aug (Fri), 17 Aug (Mon), 18 Aug (Tue). The 15th and 16th are the
        // weekend and are skipped — the range is five days, the cost is three.
        $this->assertSame(3.0, $breakdown->taken, 'Only the working days in the range are taken.');
        $this->assertSame(16.0, $breakdown->remaining());
    }

    /**
     * §10.13a — adjustments are rows and none of them is lost.
     *
     * This is the assertion a single `adjustment_days` column could not have passed:
     * two adjustments in one leave year, both surviving, both reasons intact, and the
     * balance being their sum.
     */
    public function test_two_adjustments_in_one_year_both_survive_with_their_reasons(): void
    {
        $type = $this->makeType();

        $entitlement = LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'leave_year_start' => '2026-01-01',
            'leave_year_end' => '2026-12-31',
            'accrued_days' => 10,
        ]);

        $entitlement->adjustments()->create(['days' => 3, 'reason' => 'March goodwill']);
        $entitlement->adjustments()->create(['days' => 2, 'reason' => 'August correction']);

        $this->assertSame(2, $entitlement->adjustments()->count());
        $this->assertEqualsCanonicalizing(
            ['March goodwill', 'August correction'],
            $entitlement->adjustments()->pluck('reason')->all(),
        );
        $this->assertSame(15.0, app(LeaveBalance::class)->remaining($this->employee, $type, '2026-06-01'));

        // Who and when, stamped by the model so no call site can forget them.
        $adjustment = $entitlement->adjustments()->first();
        $this->assertSame($this->actor->id, $adjustment->made_by);
        $this->assertNotNull($adjustment->made_at);
    }

    /** A pending request is shown beside the balance, never deducted from it. */
    public function test_a_pending_request_does_not_move_the_balance(): void
    {
        $type = $this->makeType();

        LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'leave_year_start' => '2026-01-01',
            'leave_year_end' => '2026-12-31',
            'accrued_days' => 10,
        ]);

        $this->requestFor($type, '2026-08-14', '2026-08-18');

        $breakdown = app(LeaveBalance::class)->for($this->employee, $type, '2026-08-14');

        $this->assertSame(10.0, $breakdown->remaining(), 'Asking has consumed nothing.');
        $this->assertSame(3.0, $breakdown->pending);
        $this->assertSame(7.0, $breakdown->remainingIfPendingApproved());
    }

    /** An uncounted type has no balance, which is not the same as a balance of zero. */
    public function test_an_uncounted_type_has_no_balance_rather_than_zero(): void
    {
        $unpaid = $this->makeType([
            'code' => 'unpaid',
            'label' => 'Unpaid Leave',
            'is_paid' => false,
            'accrual_method' => LeaveType::ACCRUAL_NONE,
            'days_per_year' => null,
        ]);

        $this->assertNull(app(LeaveBalance::class)->for($this->employee, $unpaid));
        $this->assertNull(app(LeaveEntitlementService::class)->open($this->employee, $unpaid, '2026-06-01'));
    }

    /** Withdrawing gives the days back, because the balance sums the day rows. */
    public function test_cancelling_returns_the_days_to_the_balance(): void
    {
        $type = $this->makeType();

        LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'leave_year_start' => '2026-01-01',
            'leave_year_end' => '2026-12-31',
            'accrued_days' => 10,
        ]);

        $request = $this->requestFor($type, '2026-08-14', '2026-08-18');
        app(LeaveRequestService::class)->approve($request, $this->approver());
        $this->assertSame(7.0, app(LeaveBalance::class)->remaining($this->employee, $type, '2026-08-14'));

        app(LeaveRequestService::class)->cancel($request, $this->actor);

        $this->assertSame(10.0, app(LeaveBalance::class)->remaining($this->employee, $type, '2026-08-14'));
        $this->assertSame(0, $request->fresh()->days()->count());
    }

    // ---------------------------------------------------------------- approval

    /**
     * §10.11 — a manager cannot approve their own leave with the setting on, can with
     * it off, and the activity log records the self-approval.
     *
     * The waiver leaving a trace is the part that matters: a control turned off
     * silently is worse than no control, because the record then looks as though two
     * people checked it.
     */
    public function test_self_approval_is_refused_by_default_and_recorded_when_allowed(): void
    {
        $type = $this->makeType();
        $self = $this->makeEmployee('EMP-SELF', [
            'user_id' => $this->actor->id,
            'date_of_joining' => '2020-01-01',
        ]);
        $this->employee = $self;

        $request = $this->requestFor($type, '2026-08-14', '2026-08-14');

        try {
            app(LeaveRequestService::class)->approve($request, $this->actor);
            $this->fail('Self-approval should be refused while leave.require_second_approver is on.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be approved by the person who filed it', $e->getMessage());
        }

        $this->assertTrue($request->fresh()->isPending());

        $this->set('leave.require_second_approver', false);

        app(LeaveRequestService::class)->approve($request, $this->actor);

        $this->assertTrue($request->fresh()->isApproved());
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'LeaveRequest',
            'event' => 'approved',
            'subject_id' => $request->id,
        ]);

        $activity = \Spatie\Activitylog\Models\Activity::where('subject_id', $request->id)
            ->where('event', 'approved')->latest('id')->first();

        $this->assertTrue((bool) $activity->properties['self_approved']);
    }

    /** A refusal carries its reason, and a blank one is not a refusal. */
    public function test_a_refusal_needs_a_reason(): void
    {
        $type = $this->makeType();
        $request = $this->requestFor($type, '2026-08-14', '2026-08-14');

        try {
            app(LeaveRequestService::class)->refuse($request, $this->approver(), '   ');
            $this->fail('A blank reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        app(LeaveRequestService::class)->refuse($request, $this->approver(), 'Two people are already away that week.');

        $this->assertSame(LeaveRequest::STATUS_REFUSED, $request->fresh()->status);
        $this->assertSame('Two people are already away that week.', $request->fresh()->refusal_reason);
    }

    /**
     * Two requests may not claim the same day.
     *
     * Enforced rather than warned about: unlike an over-drawn balance, charging
     * somebody twice for one day off is arithmetic nobody agreed to.
     */
    public function test_overlapping_requests_are_refused(): void
    {
        $type = $this->makeType();
        $this->requestFor($type, '2026-08-17', '2026-08-19');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('overlaps leave already requested');

        $this->requestFor($type, '2026-08-19', '2026-08-21');
    }

    /** An approved request cannot be approved again, so days cannot be generated twice. */
    public function test_an_approved_request_cannot_be_approved_again(): void
    {
        $type = $this->makeType();
        $request = $this->requestFor($type, '2026-08-14', '2026-08-14');

        app(LeaveRequestService::class)->approve($request, $this->approver());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already approved');

        app(LeaveRequestService::class)->approve($request->fresh(), $this->approver());
    }

    // ---------------------------------------------------------------- the leave year

    /**
     * §10.5 — on the calendar basis the leave year is the same for everybody,
     * whatever their joining date. That is what distinguishes it from `anniversary`.
     */
    public function test_the_calendar_leave_year_is_the_same_for_everybody(): void
    {
        $type = $this->makeType();
        $other = $this->makeEmployee('EMP-OTHER', ['date_of_joining' => '2023-09-20']);

        $mine = app(LeaveEntitlementService::class)->open($this->employee, $type, '2026-06-01');
        $theirs = app(LeaveEntitlementService::class)->open($other, $type, '2026-06-01');

        $this->assertSame('2026-01-01', $mine->leave_year_start->toDateString());
        $this->assertSame($mine->leave_year_start->toDateString(), $theirs->leave_year_start->toDateString());
        $this->assertSame($mine->leave_year_end->toDateString(), $theirs->leave_year_end->toDateString());
    }

    /** The anniversary basis is per employee, which is the whole difference. */
    public function test_the_anniversary_leave_year_follows_the_joining_date(): void
    {
        $this->set('leave.year_basis', LeaveYear::BASIS_ANNIVERSARY);

        $type = $this->makeType();
        $employee = $this->makeEmployee('EMP-ANNIV', ['date_of_joining' => '2023-09-20']);

        $entitlement = app(LeaveEntitlementService::class)->open($employee, $type, '2026-06-01');

        $this->assertSame('2025-09-20', $entitlement->leave_year_start->toDateString());
        $this->assertSame('2026-09-19', $entitlement->leave_year_end->toDateString());
    }

    /** The fiscal basis is FBR's July–June year, computed rather than read from a row. */
    public function test_the_fiscal_leave_year_runs_july_to_june(): void
    {
        $this->set('leave.year_basis', LeaveYear::BASIS_FISCAL);

        $type = $this->makeType();

        $inAugust = app(LeaveEntitlementService::class)->open($this->employee, $type, '2026-08-01');
        $this->assertSame('2026-07-01', $inAugust->leave_year_start->toDateString());
        $this->assertSame('2027-06-30', $inAugust->leave_year_end->toDateString());

        $inMarch = app(LeaveEntitlementService::class)->open($this->employee, $type, '2026-03-01');
        $this->assertSame('2025-07-01', $inMarch->leave_year_start->toDateString());
    }

    /**
     * §10.8, first case — changing the basis mid-year leaves every existing
     * entitlement's window exactly where it was.
     *
     * This is the test that fails if the window is ever derived at read time instead
     * of stored: switching to fiscal in June would move the year, restate the
     * balance, and land approved leave in a year that no longer exists.
     */
    public function test_changing_the_year_basis_does_not_move_an_existing_entitlement(): void
    {
        $type = $this->makeType();
        $entitlement = app(LeaveEntitlementService::class)->open($this->employee, $type, '2026-06-01');

        $this->assertSame('2026-01-01', $entitlement->leave_year_start->toDateString());

        $this->set('leave.year_basis', LeaveYear::BASIS_FISCAL);

        $this->assertSame('2026-01-01', $entitlement->fresh()->leave_year_start->toDateString());
        $this->assertSame('2026-12-31', $entitlement->fresh()->leave_year_end->toDateString());
    }

    // ---------------------------------------------------------------- pro-rating

    /**
     * §10.6 — both directions, plus the half-month boundary.
     *
     * On the 15th the joining month counts; on the 16th it does not. That boundary is
     * shipped behaviour rather than a setting, and it is pinned here because it would
     * otherwise be implemented three different ways.
     */
    public function test_a_mid_year_joiner_is_prorated_and_the_half_month_boundary_holds(): void
    {
        $type = $this->makeType(['days_per_year' => 12]);

        // 12 September: on or before the 15th, so September counts — 4 months of 12.
        $early = $this->makeEmployee('EMP-EARLY', ['date_of_joining' => '2026-09-12']);
        $this->assertSame(
            4.0,
            (float) app(LeaveEntitlementService::class)->open($early, $type, '2026-10-01')->accrued_days,
        );

        // 20 September: after the 15th, so October is the first month — 3 of 12.
        $late = $this->makeEmployee('EMP-LATE', ['date_of_joining' => '2026-09-20']);
        $this->assertSame(
            3.0,
            (float) app(LeaveEntitlementService::class)->open($late, $type, '2026-10-01')->accrued_days,
        );

        // The 15th itself counts; the 16th does not.
        $onFifteenth = $this->makeEmployee('EMP-15', ['date_of_joining' => '2026-09-15']);
        $onSixteenth = $this->makeEmployee('EMP-16', ['date_of_joining' => '2026-09-16']);
        $this->assertSame(4.0, (float) app(LeaveEntitlementService::class)->open($onFifteenth, $type, '2026-10-01')->accrued_days);
        $this->assertSame(3.0, (float) app(LeaveEntitlementService::class)->open($onSixteenth, $type, '2026-10-01')->accrued_days);

        // Off: the full year's days from day one.
        $this->set('leave.prorate_first_year', false);
        $full = $this->makeEmployee('EMP-FULL', ['date_of_joining' => '2026-09-20']);
        $this->assertSame(
            12.0,
            (float) app(LeaveEntitlementService::class)->open($full, $type, '2026-10-01')->accrued_days,
        );
    }

    /** Pro-rating rounds to a half day, because nothing finer can be spent. */
    public function test_proration_rounds_to_the_half_day_the_module_uses(): void
    {
        $type = $this->makeType(['days_per_year' => 14]);
        $employee = $this->makeEmployee('EMP-ROUND', ['date_of_joining' => '2026-09-20']);

        // 14 × 3/12 = 3.5 exactly. A figure like 3.4 could never be spent to zero.
        $this->assertSame(
            3.5,
            (float) app(LeaveEntitlementService::class)->open($employee, $type, '2026-10-01')->accrued_days,
        );
    }

    /** An employee who joined before the year starts is never pro-rated. */
    public function test_an_existing_employee_gets_the_whole_year(): void
    {
        $type = $this->makeType(['days_per_year' => 14]);

        $this->assertSame(
            14.0,
            (float) app(LeaveEntitlementService::class)->open($this->employee, $type, '2026-06-01')->accrued_days,
        );
    }

    /**
     * Periodic accrual: a twelfth arrives on the 1st of each month; twice a month, a
     * twenty-fourth arrives on the 1st and again on the 16th. Both are recomputed from the
     * year start on every run and capped at the year's figure, so neither can over-credit.
     */
    public function test_monthly_and_semi_monthly_accrual_tick_on_the_first_and_the_sixteenth(): void
    {
        $monthly = $this->makeType(['code' => 'monthly', 'label' => 'Monthly', 'accrual_method' => LeaveType::ACCRUAL_MONTHLY, 'days_per_year' => 24]);
        $twice = $this->makeType(['code' => 'twice', 'label' => 'Twice a month', 'accrual_method' => LeaveType::ACCRUAL_SEMI_MONTHLY, 'days_per_year' => 24]);

        $accrued = fn (LeaveType $type, string $asOf): float => (float) app(LeaveEntitlementService::class)
            ->open($this->employee, $type, $asOf)->accrued_days;

        // Calendar year: on 1 March two months are complete and the third has begun.
        $this->assertSame(6.0, $accrued($monthly, '2026-03-01'));
        $this->assertSame(6.0, $accrued($monthly, '2026-03-31'), 'nothing more arrives within the month');

        $this->assertSame(5.0, $accrued($twice, '2026-03-01'), 'four half-months complete, the fifth begun');
        $this->assertSame(5.0, $accrued($twice, '2026-03-15'), 'the 15th is still the first half');
        $this->assertSame(6.0, $accrued($twice, '2026-03-16'), 'the 16th starts the second half');

        // Capped at the year: the last period is credited on 16 December and nothing after.
        $this->assertSame(24.0, $accrued($twice, '2026-12-16'));
        $this->assertSame(24.0, $accrued($twice, '2026-12-31'));

        // Rounded to the half day the module spends in: 14 / 24 = 0.58 → 0.5 on 1 January.
        $fourteen = $this->makeType(['code' => 'fourteen', 'label' => 'Fourteen', 'accrual_method' => LeaveType::ACCRUAL_SEMI_MONTHLY, 'days_per_year' => 14]);
        $this->assertSame(0.5, $accrued($fourteen, '2026-01-01'));
    }

    /** On completion of service: nothing until twelve months are up. */
    public function test_on_completion_accrual_credits_nothing_in_the_first_year(): void
    {
        $type = $this->makeType([
            'code' => 'hajj',
            'label' => 'Hajj Leave',
            'accrual_method' => LeaveType::ACCRUAL_ON_COMPLETION,
            'days_per_year' => 30,
        ]);

        $newJoiner = $this->makeEmployee('EMP-NEW', ['date_of_joining' => '2026-06-01']);

        $this->assertSame(0.0, (float) app(LeaveEntitlementService::class)->open($newJoiner, $type, '2026-08-01')->accrued_days);
        $this->assertSame(30.0, (float) app(LeaveEntitlementService::class)->open($newJoiner, $type, '2027-07-01')->accrued_days);
    }

    /**
     * Compensatory types credit nothing, and that is the honest state.
     *
     * They accrue from approved attendance, which is phase 2. A type that quietly
     * invented an accrual here would be a balance nobody earned.
     */
    public function test_a_compensatory_type_credits_nothing_until_attendance_exists(): void
    {
        $type = $this->makeType([
            'code' => 'comp_off',
            'label' => 'Compensatory Off',
            'accrual_method' => LeaveType::ACCRUAL_COMPENSATORY,
            'days_per_year' => 12,
        ]);

        $this->assertSame(0.0, (float) app(LeaveEntitlementService::class)->open($this->employee, $type, '2026-06-01')->accrued_days);
    }

    // ---------------------------------------------------------------- carry-forward

    /**
     * §10.4 — carry-forward in all four of its stated cases.
     *
     * Off is the shipped default and the pilot's behaviour: unused days are simply
     * gone. On, the per-type cap is the limit, and a cap of 0 carries nothing even
     * for a company that carries — which is how "annual carries, casual does not" is
     * expressed without a second setting.
     */
    public function test_carry_forward_off_lapses_everything(): void
    {
        $type = $this->makeType(['days_per_year' => 14, 'max_carry_forward' => 5]);

        $entitlement = LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'leave_year_start' => '2025-01-01',
            'leave_year_end' => '2025-12-31',
            'accrued_days' => 14,
        ]);

        $next = app(LeaveEntitlementService::class)->reset($entitlement);

        $this->assertSame('2026-01-01', $next->leave_year_start->toDateString());
        $this->assertSame(0.0, (float) $next->carried_in_days, 'With the setting off, nothing carries.');
    }

    public function test_carry_forward_on_is_capped_by_the_type(): void
    {
        $this->set('leave.carry_forward', true);

        // Five days unused against a cap of three carries three; two are lost.
        $type = $this->makeType(['days_per_year' => 5, 'max_carry_forward' => 3]);

        $entitlement = LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'leave_year_start' => '2025-01-01',
            'leave_year_end' => '2025-12-31',
            'accrued_days' => 5,
        ]);

        $next = app(LeaveEntitlementService::class)->reset($entitlement);

        $this->assertSame(3.0, (float) $next->carried_in_days);
    }

    public function test_a_type_with_no_cap_carries_nothing_even_with_the_setting_on(): void
    {
        $this->set('leave.carry_forward', true);

        $casual = $this->makeType(['code' => 'casual', 'label' => 'Casual Leave', 'days_per_year' => 10, 'max_carry_forward' => 0]);

        $entitlement = LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $casual->id,
            'leave_year_start' => '2025-01-01',
            'leave_year_end' => '2025-12-31',
            'accrued_days' => 10,
        ]);

        $this->assertSame(0.0, (float) app(LeaveEntitlementService::class)->reset($entitlement)->carried_in_days);
    }

    /**
     * §10.4, last case — the failure this design exists to prevent.
     *
     * Switching carry-forward on in June must NOT resurrect days that lapsed at the
     * last reset. A carry job written as "recompute carried_in from last year's
     * unused balance" would hand everybody back leave the company had already written
     * off, and it would look like the feature working.
     */
    public function test_switching_carry_forward_on_later_does_not_resurrect_lapsed_days(): void
    {
        $type = $this->makeType(['days_per_year' => 14, 'max_carry_forward' => 5]);

        $lastYear = LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'leave_year_start' => '2025-01-01',
            'leave_year_end' => '2025-12-31',
            'accrued_days' => 14,
        ]);

        // The reset ran with carry-forward off, so nothing carried.
        $next = app(LeaveEntitlementService::class)->reset($lastYear);
        $this->assertSame(0.0, (float) $next->carried_in_days);

        // Now the company turns it on, mid-year.
        $this->set('leave.carry_forward', true);

        // A second run must leave the settled figure alone.
        app(LeaveEntitlementService::class)->reset($lastYear->fresh());

        $this->assertSame(0.0, (float) $next->fresh()->carried_in_days);
    }

    /** The roll is idempotent: run twice, nobody gains a day. */
    public function test_running_the_year_end_twice_changes_nothing(): void
    {
        $this->set('leave.carry_forward', true);
        $type = $this->makeType(['days_per_year' => 10, 'max_carry_forward' => 5]);

        $entitlement = LeaveEntitlement::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id,
            'leave_year_start' => '2025-01-01',
            'leave_year_end' => '2025-12-31',
            'accrued_days' => 10,
        ]);

        $first = app(LeaveEntitlementService::class)->reset($entitlement);
        app(LeaveEntitlementService::class)->reset($entitlement->fresh());

        $this->assertSame(5.0, (float) $first->fresh()->carried_in_days);
        $this->assertSame(
            1,
            LeaveEntitlement::where('employee_id', $this->employee->id)
                ->where('leave_year_start', '2026-01-01')->count(),
            'A second roll must not create a second entitlement for the same year.',
        );
    }

    /** Opening the year twice cannot double an allowance — the unique key is the guard. */
    public function test_opening_the_year_twice_does_not_double_the_entitlement(): void
    {
        $type = $this->makeType(['days_per_year' => 14]);

        app(LeaveEntitlementService::class)->open($this->employee, $type, '2026-06-01');
        app(LeaveEntitlementService::class)->open($this->employee, $type, '2026-06-01');

        $this->assertSame(1, LeaveEntitlement::where('employee_id', $this->employee->id)->count());
        $this->assertSame(14.0, (float) LeaveEntitlement::first()->accrued_days);
    }

    // ---------------------------------------------------------------- settings

    /**
     * §10.9 — the settings resolve the way accounting.require_second_approver does:
     * no tenant override falls back to the installation default, and a saved company
     * answer wins and keeps winning when the installation default later changes.
     */
    public function test_a_company_answer_outlives_a_change_to_the_installation_default(): void
    {
        // No override: the config default answers.
        config()->set('leave.carry_forward', false);
        $this->assertFalse((bool) setting('leave.carry_forward'));

        config()->set('leave.carry_forward', true);
        $this->assertTrue((bool) setting('leave.carry_forward'), 'With no company answer, the installation default is what applies.');

        // The company answers for itself, opting out.
        $this->set('leave.carry_forward', false);
        $this->assertFalse((bool) setting('leave.carry_forward'));

        // The installation default changes again. The company's answer stands.
        config()->set('leave.carry_forward', true);
        $this->assertFalse((bool) setting('leave.carry_forward'));
    }

    /** An unrecognised basis falls back to the documented default rather than guessing. */
    public function test_an_unknown_year_basis_falls_back_to_calendar(): void
    {
        $this->set('leave.year_basis', 'quarterly');

        $this->assertSame(LeaveYear::BASIS_CALENDAR, app(LeaveYear::class)->basis());
    }

    /** Notice warns by default, and blocks only when the company says so. */
    public function test_minimum_notice_warns_by_default_and_blocks_when_enforced(): void
    {
        // Pinned to a Monday, so "tomorrow" is a Tuesday.
        //
        // This test asks for one day off tomorrow and expects the notice rule to be what stops it. Run
        // on a Friday or a Saturday, tomorrow is a weekend day, the request covers no working days at
        // all, and the service rejects it for that instead — a failure that has nothing to do with
        // notice and appears two days in seven. Relative to now() rather than a fixed date, so this
        // does not drift out of whatever fiscal year the fixtures are built in.
        $this->travelTo(now()->next(CarbonInterface::MONDAY)->setTime(9, 0));

        $type = $this->makeType(['min_notice_days' => 7]);

        // Tomorrow, with seven days' notice expected: recorded anyway by default.
        $soon = now()->addDay()->toDateString();
        $request = $this->requestFor($type, $soon, $soon);
        $this->assertTrue($request->isPending());

        app(LeaveRequestService::class)->cancel($request, $this->actor);

        $this->set('leave.min_notice_enforced', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("needs 7 days' notice");

        $this->requestFor($type, $soon, $soon);
    }
}
