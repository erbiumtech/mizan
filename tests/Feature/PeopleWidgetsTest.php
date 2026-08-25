<?php

namespace Tests\Feature;

use App\Modules\Attendance\Filament\Widgets\AttendanceTodayOverview;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRegister;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Widgets\HeadcountOverview;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\HeadcountReports;
use App\Modules\Leave\Filament\Widgets\LeaveAwaitingDecisionOverview;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Lifecycle\Filament\Widgets\DocumentsExpiringOverview;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The people widgets — `docs/reports-expansion-plan.md` Phase 5.2.
 *
 * Four figures across four modules, and the tests concentrate on three things:
 *
 *  - **headcount reads the report's own definition of "employed on a date".** `headcountAt()` compares date
 *    *strings*, because `left_on` is a date cast and a boundary is an instant — and getting that wrong once
 *    already reported 200% turnover for a month in which one person of one left. A widget with its own
 *    `whereNull` would reproduce the bug rather than inherit the fix, so the test asserts against
 *    `HeadcountReports::summary()`.
 *  - **unmarked attendance days are on the dashboard**, because a day nobody recorded is not a day nobody
 *    worked. "12 present" for a company of thirty with eighteen missing from every figure reads as an
 *    attendance problem rather than a recording one.
 *  - **two of the four ignore the period, and say so.** A queue of unanswered leave requests and a lapsing
 *    visa are facts about now; filtering them by the dashboard's span would hide the oldest, which are the
 *    ones the widgets exist to surface.
 */
class PeopleWidgetsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const TODAY = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
        Gate::before(fn () => true);

        foreach (['employees', 'attendance', 'leave', 'lifecycle'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ────────────────────────────────────────────────── fixtures ──

    private function employee(string $name, string $joined = '2024-01-01', ?string $left = null): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-'.mb_substr(md5($name), 0, 5),
            'name' => $name,
            'date_of_joining' => $joined,
            'left_on' => $left,
            'status' => $left === null ? 1 : 0,
        ]);
    }

    private function day(Employee $employee, string $date, string $status, int $late = 0): AttendanceDay
    {
        return AttendanceDay::create([
            'employee_id' => $employee->getKey(),
            'date' => $date,
            'status' => $status,
            'late_minutes' => $late,
        ]);
    }

    // ─────────────────── headcount ──

    /** Headcount, joiners and leavers over the period, and the net change stated. */
    public function test_headcount_reports_joiners_leavers_and_the_net(): void
    {
        $this->employee('Established', '2024-01-01');
        $this->employee('Joiner', '2027-02-05');
        $this->employee('Leaver', '2024-01-01', left: '2027-02-10');

        Livewire::test(HeadcountOverview::class, ['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('+0 over the period');
    }

    /**
     * The figures are the Headcount Movement report's own.
     *
     * Asserted against `HeadcountReports::summary()` rather than a literal, so widget and report share one
     * definition of who counts as employed — the definition whose date-versus-instant subtlety already caused
     * a real bug once.
     */
    public function test_headcount_agrees_with_the_report_service(): void
    {
        $this->employee('Established', '2024-01-01');
        $this->employee('Joiner', '2027-02-05');

        $summary = app(HeadcountReports::class)->summary('2027-02-01', self::TODAY);

        $this->assertSame(2, $summary['headcount']);
        $this->assertSame(1, $summary['joiners']);

        Livewire::test(HeadcountOverview::class, ['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee((string) $summary['headcount']);
    }

    /**
     * Somebody's last day still counts as employed.
     *
     * The reading `headcountAt()` takes and that Phases 3.7, 3.9 and 3.12 were each brought into line with.
     * A widget that dropped them a day early would put the dashboard at odds with four reports.
     */
    public function test_the_last_day_of_employment_still_counts(): void
    {
        $this->employee('Leaver', '2024-01-01', left: self::TODAY);

        $this->assertSame(1, app(HeadcountReports::class)->summary('2027-02-01', self::TODAY)['headcount']);
        $this->assertSame(0, app(HeadcountReports::class)->summary('2027-02-20', '2027-02-25')['headcount']);
    }

    /** Opening plus joiners less leavers is the closing figure, which is what makes the net add up. */
    public function test_the_opening_figure_makes_the_net_add_up(): void
    {
        $this->employee('Established', '2024-01-01');
        $this->employee('Joiner', '2027-02-05');
        $this->employee('Leaver', '2024-01-01', left: '2027-02-10');

        $summary = app(HeadcountReports::class)->summary('2027-02-01', self::TODAY);

        $this->assertSame(
            $summary['headcount'],
            $summary['opening'] + $summary['joiners'] - $summary['leavers'],
        );
    }

    // ─────────────────── attendance today ──

    /** Present, late and on leave for the day. */
    public function test_attendance_reports_present_late_and_on_leave(): void
    {
        $ayesha = $this->employee('Ayesha');
        $bilal = $this->employee('Bilal');
        $danish = $this->employee('Danish');

        $this->day($ayesha, self::TODAY, AttendanceDay::STATUS_PRESENT);
        $this->day($bilal, self::TODAY, AttendanceDay::STATUS_PRESENT, late: 25);
        $this->day($danish, self::TODAY, AttendanceDay::STATUS_ON_LEAVE);

        $summary = app(AttendanceRegister::class)->daySummary(self::TODAY);

        $this->assertSame(2, $summary['present']);
        $this->assertSame(1, $summary['late']);
        $this->assertSame(1, $summary['on_leave']);
    }

    /**
     * A half day and a day worked from home are both present.
     *
     * One is a shorter day and the other a different desk; a figure that excluded them would answer "who did
     * a full day in the office" under a heading that says present.
     */
    public function test_half_days_and_home_working_count_as_present(): void
    {
        $this->day($this->employee('Half'), self::TODAY, AttendanceDay::STATUS_HALF_DAY);
        $this->day($this->employee('Home'), self::TODAY, AttendanceDay::STATUS_WORK_FROM_HOME);

        $this->assertSame(2, app(AttendanceRegister::class)->daySummary(self::TODAY)['present']);
    }

    /**
     * Unmarked days are counted and shown, because they are the finding.
     *
     * Without them the widget says "1 present" for a company of three and the other two are in no figure at
     * all — which reads as absence rather than as nobody having filled the register in.
     */
    public function test_unmarked_days_are_counted_and_shown(): void
    {
        $this->day($this->employee('Present'), self::TODAY, AttendanceDay::STATUS_PRESENT);
        $this->day($this->employee('Unknown'), self::TODAY, AttendanceDay::STATUS_NOT_MARKED);

        $this->assertSame(1, app(AttendanceRegister::class)->daySummary(self::TODAY)['unmarked']);

        Livewire::test(AttendanceTodayOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('nobody recorded these, so they are in no figure above');
    }

    /**
     * Lateness is counted from days somebody attended, not as a status.
     *
     * A late arrival is present *and* late, so a company where everybody came in late is fully present — and
     * a figure that treated late as its own status would report them all absent.
     */
    public function test_a_late_arrival_is_present_and_late(): void
    {
        $this->day($this->employee('Late'), self::TODAY, AttendanceDay::STATUS_PRESENT, late: 40);

        $summary = app(AttendanceRegister::class)->daySummary(self::TODAY);

        $this->assertSame(1, $summary['present']);
        $this->assertSame(1, $summary['late']);
    }

    /** The period's end chooses the day, so a past date answers who was in then. */
    public function test_the_period_end_chooses_the_day(): void
    {
        $ayesha = $this->employee('Ayesha');
        $this->day($ayesha, '2027-02-10', AttendanceDay::STATUS_PRESENT);
        $this->day($ayesha, self::TODAY, AttendanceDay::STATUS_ON_LEAVE);

        $this->assertSame(1, app(AttendanceRegister::class)->daySummary('2027-02-10')['present']);
        $this->assertSame(0, app(AttendanceRegister::class)->daySummary(self::TODAY)['present']);

        Livewire::test(AttendanceTodayOverview::class, ['periodTo' => '2027-02-10'])
            ->assertSuccessful()
            ->assertSee('10 Feb 2027');
    }

    /** And today is called today rather than dated. */
    public function test_today_is_called_today(): void
    {
        Livewire::test(AttendanceTodayOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('today');
    }

    // ─────────────────── leave awaiting a decision ──

    /** Pending requests are counted, with the longest wait. */
    public function test_pending_leave_requests_are_counted_with_the_longest_wait(): void
    {
        $this->pendingRequest('2027-03-01', createdAt: '2027-02-05 09:00:00');
        $this->pendingRequest('2027-03-10', createdAt: '2027-02-17 09:00:00');

        Livewire::test(LeaveAwaitingDecisionOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('14 days')
            ->assertSee('a queue, so whatever the period');
    }

    /**
     * A request whose leave has already begun is the sharpest row in the queue.
     *
     * Somebody is either off without approval or at work when they expected not to be, and a plain count
     * cannot tell that apart from a request for next month.
     */
    public function test_leave_that_has_already_started_is_called_out(): void
    {
        $this->pendingRequest('2027-02-15');

        Livewire::test(LeaveAwaitingDecisionOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('leave began before anybody answered');
    }

    /** An answered request is not in the queue. */
    public function test_an_answered_request_is_not_in_the_queue(): void
    {
        $request = $this->pendingRequest('2027-03-01');
        $request->update(['status' => LeaveRequest::STATUS_APPROVED]);

        $this->assertSame(0, LeaveRequest::query()->pending()->count());

        Livewire::test(LeaveAwaitingDecisionOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('nothing is waiting');
    }

    /**
     * The queue ignores the period, and a request outside it proves so.
     *
     * Filtering by the dashboard's span would hide the oldest requests, which are exactly the ones the widget
     * exists to surface.
     */
    public function test_the_queue_ignores_the_period(): void
    {
        $this->pendingRequest('2027-03-01', createdAt: '2026-08-01 09:00:00');

        Livewire::test(LeaveAwaitingDecisionOverview::class, ['periodFrom' => '2027-02-01', 'periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('a queue, so whatever the period');
    }

    private function pendingRequest(string $start, ?string $createdAt = null): LeaveRequest
    {
        // `code` and `label`, not `name` — the latter is not fillable, so an insert built around it dropped
        // both and failed the NOT NULL on `code`.
        $type = LeaveType::firstOrCreate(
            ['code' => 'annual'],
            ['label' => 'Annual', 'is_active' => true, 'days_per_year' => 20, 'is_paid' => true],
        );

        $request = LeaveRequest::create([
            'employee_id' => $this->employee('Requester '.$start)->getKey(),
            'leave_type_id' => $type->getKey(),
            'from_date' => $start,
            'to_date' => $start,
            'status' => LeaveRequest::STATUS_PENDING,
            'days' => 1,
        ]);

        if ($createdAt !== null) {
            $request->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $request->fresh();
    }

    // ─────────────────── documents expiring ──

    /** Documents lapsing inside the window are counted. */
    public function test_documents_expiring_soon_are_counted(): void
    {
        $this->document('2027-03-05');

        Livewire::test(DocumentsExpiringOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('visas, licences and contracts');
    }

    /**
     * Already expired is counted apart from expiring.
     *
     * Both are in `due()`, and folding them together would put a lapsed work permit in the same figure as one
     * with three weeks left — a breach today against a diary entry.
     */
    public function test_expired_documents_are_counted_apart(): void
    {
        $this->document('2027-01-05');

        Livewire::test(DocumentsExpiringOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('still reported, because an expired document stays expired');
    }

    /** With nothing lapsing it says so. */
    public function test_it_says_when_nothing_is_lapsing(): void
    {
        Livewire::test(DocumentsExpiringOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('nothing lapses this month')
            ->assertSee('nothing has lapsed');
    }

    private function document(string $expires): EmployeeDocument
    {
        return EmployeeDocument::create([
            'employee_id' => $this->employee('Holder '.$expires)->getKey(),
            'kind' => 'visa',
            'number' => 'V-'.mb_substr(md5($expires), 0, 6),
            'expires_on' => $expires,
        ]);
    }

    // ─────────────────── the group's own rules ──

    /** All four take the period, are lazy, and sit in the people band. */
    public function test_the_people_widgets_follow_the_dashboard_rules(): void
    {
        foreach ([
            HeadcountOverview::class => 40,
            AttendanceTodayOverview::class => 41,
            LeaveAwaitingDecisionOverview::class => 42,
            DocumentsExpiringOverview::class => 43,
        ] as $widget => $sort) {
            $this->assertTrue(property_exists($widget, 'periodTo'), class_basename($widget).' takes no period');
            $this->assertTrue((new \ReflectionProperty($widget, 'isLazy'))->getValue(), class_basename($widget).' is not lazy');
            $this->assertSame($sort, (new \ReflectionProperty($widget, 'sort'))->getValue());
        }
    }

    /** Each gates on its module. */
    public function test_each_widget_is_gated_on_its_module(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())->update(['enabled' => false]);
        modules()->flush();

        foreach ([
            HeadcountOverview::class,
            AttendanceTodayOverview::class,
            LeaveAwaitingDecisionOverview::class,
            DocumentsExpiringOverview::class,
        ] as $widget) {
            $this->assertFalse($widget::canView(), class_basename($widget).' renders without its module');
        }
    }
}
