<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\PayslipComponentInput;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\RecurringTask;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Modules\Performance\Services\TaskRoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Recurring tasks — docs/saas-launch-plan.md §8.2. The roll is idempotent;
 * working-days-only skips weekends and holidays, asked separately; a miss
 * writes one debit; an excuse writes one reversal; settle writes no payslip.
 */
class RecurringTaskTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private Employee $it;

    private Employee $office;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday.
        Carbon::setTestNow('2026-11-09 00:05:00');

        // The tenant-aware commands make the tenant current; the single-database
        // suite has no tenant connection to switch to, so that task is left out,
        // as the other command tests leave it out.
        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
            \App\Multitenancy\Tasks\SwitchTenantFilesystemTask::class,
        ]]);

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        foreach (['performance', 'employees', 'attendance', 'payroll'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->it = Employee::create(['user_id' => $this->actor->getKey(), 'employee_id' => 'EMP-1', 'name' => 'IT', 'gender' => 'Male', 'is_active' => true, 'department' => 'IT', 'date_of_joining' => '2020-01-01']);
        $this->office = Employee::create(['employee_id' => 'EMP-2', 'name' => 'Office', 'gender' => 'Female', 'is_active' => true, 'department' => 'Office Staff', 'date_of_joining' => '2020-01-01']);
        Employee::create(['employee_id' => 'EMP-3', 'name' => 'Left', 'gender' => 'Male', 'is_active' => false, 'department' => 'IT', 'date_of_joining' => '2020-01-01']);
    }

    private function roller(): TaskRoller
    {
        return app(TaskRoller::class);
    }

    private function task(array $attributes = []): RecurringTask
    {
        return RecurringTask::create(array_merge([
            'title' => 'Daily standup note', 'cadence' => RecurringTask::CADENCE_DAILY, 'due_time' => '10:00:00',
            'grace_hours' => 2, 'penalty_points' => 3, 'penalty_amount' => 500,
        ], $attributes));
    }

    public function test_the_roll_makes_one_occurrence_per_active_assignee_and_is_idempotent(): void
    {
        $this->task();
        $this->task(['title' => 'IT backup check', 'assignee_kind' => RecurringTask::ASSIGNEE_DEPARTMENT, 'assignee_ref' => 'IT']);
        $this->task(['title' => 'Weekly report', 'cadence' => RecurringTask::CADENCE_WEEKLY, 'due_weekday' => 5]);
        $this->task(['title' => 'Monthly stock count', 'cadence' => RecurringTask::CADENCE_MONTHLY, 'due_day_of_month' => 9]);
        $this->task(['title' => 'Switched off', 'is_active' => false]);

        $first = $this->roller()->roll(Carbon::parse('2026-11-09'));
        $this->assertSame(['created' => 5, 'missed' => 0], $first, 'Everyone ×2 (daily, monthly on the 9th) + IT ×1; Friday\'s task and the inactive one wait.');

        $second = $this->roller()->roll(Carbon::parse('2026-11-09'));
        $this->assertSame(0, $second['created'], 'A second roll on the same day created rows.');
        $this->assertSame(5, TaskOccurrence::count());

        $this->assertSame(0, TaskOccurrence::whereHas('employee', fn ($q) => $q->where('is_active', false))->count());
    }

    public function test_working_days_only_skips_weekends_and_the_employees_own_holidays(): void
    {
        $this->task();
        Holiday::create(['date' => '2026-11-10', 'name' => 'Local day off']);

        $this->assertSame(0, $this->roller()->roll(Carbon::parse('2026-11-07'))['created'], 'A Saturday was rolled.');
        $this->assertSame(0, $this->roller()->roll(Carbon::parse('2026-11-10'))['created'], 'A holiday was rolled.');
        $this->assertSame(2, $this->roller()->roll(Carbon::parse('2026-11-11'))['created']);

        $this->task(['title' => 'Every calendar day', 'working_days_only' => false]);
        $this->assertSame(2, $this->roller()->roll(Carbon::parse('2026-11-07'))['created']);
    }

    public function test_a_miss_past_the_grace_writes_one_debit_and_an_excuse_one_reversal(): void
    {
        $this->task();
        $this->roller()->roll(Carbon::parse('2026-11-09'));

        // 11:30: due at 10:00 with two hours' grace — not yet.
        Carbon::setTestNow('2026-11-09 11:30:00');
        $this->assertSame(0, $this->roller()->markMisses(now()));

        // The IT person finishes in time; the office person does not.
        $mine = TaskOccurrence::where('employee_id', $this->it->getKey())->firstOrFail();
        $this->roller()->complete($mine, 'Posted in the channel', $this->actor);
        $this->assertSame(TaskOccurrence::STATUS_COMPLETED, $mine->fresh()->status());

        Carbon::setTestNow('2026-11-09 12:01:00');
        $this->assertSame(1, $this->roller()->markMisses(now()));
        $this->assertSame(0, $this->roller()->markMisses(now()), 'A miss was marked twice.');

        $theirs = TaskOccurrence::where('employee_id', $this->office->getKey())->firstOrFail();
        $this->assertSame(TaskOccurrence::STATUS_MISSED, $theirs->status());
        $this->assertSame(1, MeritEntry::count());
        $entry = $theirs->meritEntry;
        $this->assertSame(-3, $entry->points);
        $this->assertSame(MeritEntry::SOURCE_TASK, $entry->source_type);
        $this->assertSame('2026-11-09', $entry->occurred_on->toDateString());

        try {
            $this->roller()->complete($theirs->fresh(), null, $this->actor);
            $this->fail('A missed task was completed.');
        } catch (InvalidArgumentException) {
        }

        $this->roller()->excuse($theirs->fresh(), $this->actor, 'Client meeting ran over');
        $this->assertSame(TaskOccurrence::STATUS_EXCUSED, $theirs->fresh()->status());
        $this->assertSame(2, MeritEntry::count());
        $this->assertSame(0, (int) MeritEntry::where('employee_id', $this->office->getKey())->sum('points'));
        $this->assertNotNull($theirs->fresh()->missed_at, 'An excuse erased the miss.');

        $this->expectException(InvalidArgumentException::class);
        $this->roller()->excuse($theirs->fresh(), $this->actor, 'twice');
    }

    public function test_settle_lists_the_months_unexcused_penalties_and_writes_no_payslip(): void
    {
        $this->task();
        $this->roller()->roll(Carbon::parse('2026-11-09'));
        Carbon::setTestNow('2026-11-09 13:00:00');
        $this->roller()->markMisses(now());

        $rows = $this->roller()->monthlyPenalties(Carbon::parse('2026-11-01'));
        $this->assertCount(2, $rows);
        $this->assertSame(500.0, $rows->first()['amount']);

        $this->artisan('tasks:settle', ['--month' => '2026-11-15', '--tenant' => [$this->tenant->getKey()]])
            ->expectsOutputToContain('Nothing was written to any payslip')
            ->assertSuccessful();

        $this->assertSame(0, PayslipComponentInput::count());

        // And the roll by command is the same roll: nothing new on a second run.
        $this->artisan('tasks:roll', ['--date' => '2026-11-09', '--tenant' => [$this->tenant->getKey()]])
            ->expectsOutputToContain('0 occurrence(s) created')
            ->assertSuccessful();

        $mine = TaskOccurrence::where('employee_id', $this->it->getKey())->firstOrFail();
        $this->roller()->excuse($mine, $this->actor, 'Was travelling');
        $this->assertCount(1, $this->roller()->monthlyPenalties(Carbon::parse('2026-11-01')));
    }

    public function test_the_api_lists_and_completes_the_callers_own_tasks_only(): void
    {
        $this->task();
        $this->roller()->roll(Carbon::parse('2026-11-09'));

        $this->getJson('/api/v1/my-tasks')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.status', TaskOccurrence::STATUS_OPEN);

        $theirs = TaskOccurrence::where('employee_id', $this->office->getKey())->firstOrFail();
        $this->postJson("/api/v1/my-tasks/{$theirs->getKey()}/complete")->assertNotFound();

        $mine = TaskOccurrence::where('employee_id', $this->it->getKey())->firstOrFail();
        $this->postJson("/api/v1/my-tasks/{$mine->getKey()}/complete", ['note' => 'Done'])
            ->assertOk()
            ->assertJsonPath('data.status', TaskOccurrence::STATUS_COMPLETED);

        $this->postJson("/api/v1/my-tasks/{$mine->getKey()}/complete")->assertStatus(422);
    }
}
