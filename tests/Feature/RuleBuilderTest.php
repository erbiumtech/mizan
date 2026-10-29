<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Announcement;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Rules\Rule;
use App\Modules\Employees\Rules\RuleEvaluator;
use App\Modules\Employees\Rules\RuleRegistry;
use App\Modules\Employees\Rules\Window;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Performance\Models\Badge;
use App\Modules\Performance\Models\DailyReport;
use App\Modules\Performance\Models\EmployeeBadge;
use App\Modules\Performance\Models\RecurringTask;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Modules\Performance\Services\Badges;
use App\Modules\Performance\Services\MeritLedger;
use Database\Seeders\BadgeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The rule builder — docs/saas-launch-plan.md §8.9: a form over two registries,
 * not a language. The claims: a stored rule can hold nothing the registries do
 * not know; the evaluator reads attributes and metrics over windows and ORs
 * groups of ANDed conditions; badges award themselves once; a task and an
 * announcement can be aimed by rule.
 */
class RuleBuilderTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $ayesha;

    private Employee $bilal;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 09:00:00');
        Notification::fake();
        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);

        $this->actingAs($this->makeUser('Administrator', 'rules-admin@test.local'));
        $this->setCurrentTenant();

        foreach (['performance', 'employees', 'attendance', 'leave'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $this->tenant->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();

        $this->ayesha = Employee::create([
            'user_id' => $this->makeUser('Employee', 'ayesha@test.local')->getKey(), 'employee_id' => 'EMP-1', 'name' => 'Ayesha', 'gender' => 'Female',
            'is_active' => true, 'date_of_joining' => '2019-03-01', 'department' => 'Sales', 'designation' => 'Executive',
        ]);
        $this->bilal = Employee::create([
            'user_id' => $this->makeUser('Employee', 'bilal@test.local')->getKey(), 'employee_id' => 'EMP-2', 'name' => 'Bilal', 'gender' => 'Male',
            'is_active' => true, 'date_of_joining' => '2026-08-15', 'department' => 'Accounts', 'designation' => 'Clerk',
        ]);
    }

    private function evaluator(): RuleEvaluator
    {
        return app(RuleEvaluator::class);
    }

    private function rule(array ...$conditions): array
    {
        return ['any' => [['all' => $conditions]]];
    }

    // ───────────────────────────────────────────────────────── sanitising ──

    public function test_a_rule_keeps_only_what_the_registries_know(): void
    {
        $this->assertArrayHasKey('attendance.late_marks', RuleRegistry::fields(), 'Attendance registered its metrics');
        $this->assertArrayHasKey('leave.unplanned_days', RuleRegistry::fields());
        $this->assertArrayHasKey('merit.balance', RuleRegistry::fields());

        $rule = Rule::from(['any' => [
            ['all' => [
                ['field' => 'department', 'operator' => '=', 'value' => 'Sales'],
                ['field' => 'salary', 'operator' => '>=', 'value' => 1],              // no such field
                ['field' => 'department', 'operator' => '>=', 'value' => 'Sales'],   // not an operator for a select
                ['field' => 'years_of_service', 'operator' => '>=', 'value' => 'abc'], // not a number
                ['field' => 'attendance.late_marks', 'operator' => '<=', 'value' => '2', 'window' => 'never'],
                ['field' => 'date_of_joining', 'operator' => 'between', 'value' => '2026-01-01, 2026-12-31'],
            ]],
            ['all' => [['field' => 'nonsense', 'operator' => '=', 'value' => 1]]],   // a group that empties out goes
            'not even a group',
        ]]);

        $this->assertSame(['any' => [['all' => [
            ['field' => 'department', 'operator' => '=', 'value' => 'Sales'],
            ['field' => 'attendance.late_marks', 'operator' => '<=', 'value' => 2.0, 'window' => 'this_month'],
            ['field' => 'date_of_joining', 'operator' => 'between', 'value' => ['2026-01-01', '2026-12-31']],
        ]]]], $rule->toArray());

        $this->assertTrue(Rule::from(null)->isEmpty());
        $this->assertTrue(Rule::from(['any' => [['all' => [['field' => 'x']]]]])->isEmpty());

        // The models store the sanitised form and read null for an empty one.
        $badge = Badge::create(['name' => 'Scrubbed', 'points' => 1, 'rule' => ['any' => [['all' => [['field' => 'nope', 'operator' => '=', 'value' => 1]]]]]]);
        $this->assertNull($badge->fresh()->rule);
        $this->assertFalse($badge->fresh()->hasRule());
    }

    // ───────────────────────────────────────────────────────── evaluating ──

    public function test_attributes_are_read_from_the_employee_row(): void
    {
        $this->assertTrue($this->evaluator()->matches($this->ayesha, $this->rule(['field' => 'department', 'operator' => '=', 'value' => 'sales'])), 'case does not matter');
        $this->assertFalse($this->evaluator()->matches($this->bilal, $this->rule(['field' => 'department', 'operator' => '=', 'value' => 'Sales'])));
        $this->assertTrue($this->evaluator()->matches($this->bilal, $this->rule(['field' => 'department', 'operator' => 'in', 'value' => ['Accounts', 'Admin']])));
        $this->assertTrue($this->evaluator()->matches($this->ayesha, $this->rule(['field' => 'years_of_service', 'operator' => '>=', 'value' => 5])));
        $this->assertFalse($this->evaluator()->matches($this->bilal, $this->rule(['field' => 'years_of_service', 'operator' => '>=', 'value' => 5])));
        $this->assertTrue($this->evaluator()->matches($this->bilal, $this->rule(['field' => 'date_of_joining', 'operator' => 'between', 'value' => ['2026-08-01', '2026-08-31']])));
        $this->assertTrue($this->evaluator()->matches($this->bilal, $this->rule(['field' => 'manager', 'operator' => '!=', 'value' => '1'])), 'no manager is not manager 1');
        $this->assertFalse($this->evaluator()->matches($this->bilal, $this->rule(['field' => 'manager', 'operator' => '=', 'value' => '1'])));
    }

    public function test_groups_are_ored_and_conditions_within_a_group_anded(): void
    {
        $rule = ['any' => [
            ['all' => [['field' => 'department', 'operator' => '=', 'value' => 'Sales'], ['field' => 'years_of_service', 'operator' => '>=', 'value' => 10]]],
            ['all' => [['field' => 'designation', 'operator' => '=', 'value' => 'Clerk']]],
        ]];

        $this->assertFalse($this->evaluator()->matches($this->ayesha, $rule), 'Sales but not ten years');
        $this->assertTrue($this->evaluator()->matches($this->bilal, $rule), 'the second group');
        $this->assertSame(['EMP-2'], $this->evaluator()->matching($rule)->pluck('employee_id')->all());
        $this->assertFalse($this->evaluator()->matches($this->ayesha, null), 'no rule matches nobody');
    }

    public function test_metrics_are_counted_over_the_window(): void
    {
        $recorder = app(\App\Modules\Attendance\Services\AttendanceRecorder::class);
        // October: Ayesha present every weekday, two late marks; Bilal absent thrice.
        foreach (Carbon::parse('2026-10-01')->daysUntil('2026-10-31') as $day) {
            if (! $day->isWeekday()) {
                continue;
            }
            AttendanceDay::create(['employee_id' => $this->ayesha->getKey(), 'date' => $day->toDateString(), 'status' => AttendanceDay::STATUS_PRESENT, 'late_minutes' => in_array($day->day, [6, 20], true) ? 15 : 0]);
            // 1 and 2 October are Thursday and Friday; the 5th the Monday after. Three absences in 22 working days.
            AttendanceDay::create(['employee_id' => $this->bilal->getKey(), 'date' => $day->toDateString(), 'status' => in_array($day->day, [1, 2, 5], true) ? AttendanceDay::STATUS_ABSENT : AttendanceDay::STATUS_PRESENT]);
        }

        $lastMonth = Window::make(Window::LAST_MONTH, Carbon::now());
        $this->assertSame('2026-10-01', $lastMonth->fromDate());
        $this->assertSame('2026-10-31', $lastMonth->toDate());

        $this->assertSame(2, RuleRegistry::metric('attendance.late_marks')->value($this->ayesha, $lastMonth));
        $this->assertSame(0, RuleRegistry::metric('attendance.late_marks')->value($this->ayesha, Window::make(Window::THIS_MONTH, Carbon::now())));
        $this->assertSame(1.0, RuleRegistry::metric('attendance.present_ratio')->value($this->ayesha, $lastMonth));
        $this->assertSame(3, RuleRegistry::metric('attendance.absent_days')->value($this->bilal, $lastMonth));
        $this->assertEqualsWithDelta(19 / 22, RuleRegistry::metric('attendance.present_ratio')->value($this->bilal, $lastMonth), 0.001);

        // Tasks and reports.
        $task = RecurringTask::create(['title' => 'Note', 'cadence' => RecurringTask::CADENCE_DAILY, 'due_time' => '10:00:00', 'grace_hours' => 1]);
        foreach (['2026-10-05' => 'missed_at', '2026-10-06' => 'completed_at', '2026-10-07' => 'completed_at', '2026-10-08' => 'excused_at'] as $date => $stamp) {
            TaskOccurrence::create(['recurring_task_id' => $task->getKey(), 'employee_id' => $this->bilal->getKey(), 'due_on' => $date, 'due_at' => $date.' 10:00:00', $stamp => $date.' 12:00:00'] + ($stamp === 'excused_at' ? ['missed_at' => $date.' 11:00:00'] : []));
        }
        $this->assertSame(1, RuleRegistry::metric('tasks.missed')->value($this->bilal, $lastMonth));
        $this->assertEqualsWithDelta(2 / 3, RuleRegistry::metric('tasks.completed_ratio')->value($this->bilal, $lastMonth), 0.001, 'excused counts for neither');

        foreach (Carbon::parse('2026-10-26')->daysUntil('2026-10-30') as $day) {
            DailyReport::create(['employee_id' => $this->ayesha->getKey(), 'report_date' => $day->toDateString(), 'body' => 'Did things', 'submitted_at' => $day->copy()->setTime(18, 0)]);
        }
        $this->assertSame(5, RuleRegistry::metric('reports.on_time_streak_days')->value($this->ayesha, $lastMonth), 'the last working week, every day on time');
        $this->assertEqualsWithDelta(5 / 22, RuleRegistry::metric('reports.filed_ratio')->value($this->ayesha, $lastMonth), 0.001);

        // Merit and leave.
        app(MeritLedger::class)->record($this->ayesha, 7, 'Test credit');
        $this->assertSame(7, RuleRegistry::metric('merit.balance')->value($this->ayesha, Window::make(Window::THIS_MONTH, Carbon::now())));

        $type = LeaveType::create(['label' => 'Sick', 'code' => 'SL', 'is_active' => true]);
        LeaveRequest::create(['employee_id' => $this->bilal->getKey(), 'leave_type_id' => $type->getKey(), 'from_date' => '2026-10-12', 'to_date' => '2026-10-13', 'days' => 2, 'status' => LeaveRequest::STATUS_APPROVED, 'reason' => 'Fever']);
        $this->assertSame(2.0, RuleRegistry::metric('leave.unplanned_days')->value($this->bilal, $lastMonth), 'asked for on the day: unplanned');
        LeaveRequest::create(['employee_id' => $this->bilal->getKey(), 'leave_type_id' => $type->getKey(), 'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'days' => 2, 'status' => LeaveRequest::STATUS_APPROVED, 'reason' => 'Wedding']);
        $this->assertSame(0.0, RuleRegistry::metric('leave.unplanned_days')->value($this->bilal, Window::make(Window::THIS_MONTH, Carbon::parse('2026-12-15'))), 'asked for weeks ahead: planned');

        // And through a rule, with the window chosen.
        $this->assertTrue($this->evaluator()->matches($this->bilal, $this->rule(['field' => 'attendance.absent_days', 'operator' => '>=', 'value' => 3, 'window' => 'last_month'])));
        $this->assertFalse($this->evaluator()->matches($this->bilal, $this->rule(['field' => 'attendance.absent_days', 'operator' => '>=', 'value' => 3, 'window' => 'this_month'])));
    }

    // ─────────────────────────────────────────────────────────── callers ──

    public function test_a_badge_with_a_rule_awards_itself_once(): void
    {
        $badge = Badge::create(['name' => 'Veteran', 'points' => 5, 'rule' => $this->rule(['field' => 'years_of_service', 'operator' => '>=', 'value' => 5])]);
        Badge::create(['name' => 'By hand only', 'points' => 5]);

        $first = app(Badges::class)->awardByRule();
        $this->assertSame(['awarded' => 1, 'badges' => 1], $first);
        $this->assertSame(['EMP-1'], EmployeeBadge::query()->with('employee')->get()->map(fn ($a) => $a->employee->employee_id)->all());
        $this->assertStringContainsString('Qualified by rule', EmployeeBadge::sole()->reason);

        $this->assertSame(['awarded' => 0, 'badges' => 1], app(Badges::class)->awardByRule(), 'holding the badge is the idempotency');

        $this->artisan('badges:award', ['--tenant' => [$this->tenant->getKey()]])
            ->expectsOutputToContain('0 badge(s) awarded across 1 rule(s)')
            ->assertSuccessful();

        $badge->update(['is_active' => false]);
        $this->assertSame(['awarded' => 0, 'badges' => 0], app(Badges::class)->awardByRule());
    }

    public function test_the_seeded_badges_carry_rules_the_registry_knows(): void
    {
        (new BadgeSeeder)->run();
        (new BadgeSeeder)->run();

        $this->assertSame(['Five Years', 'Perfect Month', 'Reliable Reporter'], Badge::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame('years_of_service', Badge::where('name', 'Five Years')->first()->rule['any'][0]['all'][0]['field']);
        $this->assertCount(2, Badge::where('name', 'Perfect Month')->first()->rule['any'][0]['all']);

        $this->assertSame(1, app(Badges::class)->awardByRule()['awarded'], 'Ayesha has her five years');
    }

    public function test_a_task_and_an_announcement_can_be_aimed_by_rule(): void
    {
        $task = RecurringTask::create([
            'title' => 'Weekly pipeline note', 'cadence' => RecurringTask::CADENCE_DAILY, 'due_time' => '10:00:00',
            'assignee_kind' => RecurringTask::ASSIGNEE_RULE,
            'assignee_rule' => $this->rule(['field' => 'department', 'operator' => '=', 'value' => 'Sales']),
        ]);
        $this->assertSame(['EMP-1'], $task->assignees()->pluck('employee_id')->all());
        $this->assertSame('By rule', $task->assigneeLabel());

        $announcement = Announcement::create([
            'title' => 'New starters: welcome session', 'body' => 'Friday, 4pm.',
            'audience_kind' => Announcement::AUDIENCE_RULE,
            'audience_rule' => $this->rule(['field' => 'years_of_service', 'operator' => '<=', 'value' => 1]),
        ]);
        $this->assertSame(['bilal@test.local'], $announcement->audienceUsers()->pluck('email')->all());

        $told = $announcement->publish();
        $this->assertSame(1, $told);
        $this->assertSame(1, Announcement::query()->forUser($this->bilal->user)->count());
        $this->assertSame(0, Announcement::query()->forUser($this->ayesha->user)->count());

        $blank = Announcement::create(['title' => 'x', 'body' => 'y', 'audience_kind' => Announcement::AUDIENCE_RULE]);
        $this->expectException(\InvalidArgumentException::class);
        $blank->publish();
    }
}
