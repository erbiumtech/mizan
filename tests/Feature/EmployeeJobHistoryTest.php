<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeJobHistory;
use App\Modules\Employees\Services\JobHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Effective-dated job facts — docs/hrms-plan.md §3, §10.13e.
 *
 * `designation`, `department` and `manager_id` are overwritten in place on
 * `employees`, so "who could approve for this employee in March" has no answer
 * today. Leave approval routes through `manager_id` via `EmployeeAccess`: the
 * approval itself survives on `decided_by`, the authority behind it does not.
 * These tests are what say it does now.
 */
class EmployeeJobHistoryTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private User $actor;

    private JobHistory $history;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => 1]);
        $this->actingAs($this->actor);
        $this->setCurrentTenant();

        $this->history = new JobHistory;
    }

    private function makeEmployee(string $employeeId, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => $employeeId,
            'name' => $employeeId,
            'designation' => 'Developer',
            'department' => 'IT',
            'gender' => 'Male',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * THE test. Everything else here exists to support this one.
     *
     * An employee moves from one manager to another in June. Asked about March,
     * the service must still name the March manager — that is the question a
     * leave approval decided in March can no longer answer on its own.
     */
    public function test_manager_on_returns_the_manager_in_place_at_that_date(): void
    {
        $old = $this->makeEmployee('EMP-OLD');
        $new = $this->makeEmployee('EMP-NEW');
        $employee = $this->makeEmployee('EMP-1', ['manager_id' => $old->id]);

        $this->history->record($employee, ['manager_id' => $old->id], '2026-01-01', 'hired');
        $this->history->record($employee, ['manager_id' => $new->id], '2026-06-01', 'transfer');

        // The employee reports to the new manager now, and `employees.manager_id`
        // says so — which is exactly why March has to come from somewhere else.
        $this->assertSame($new->id, $employee->fresh()->manager_id);

        $this->assertSame($old->id, $this->history->managerOn($employee, '2026-03-15')?->id);
        $this->assertSame($new->id, $this->history->managerOn($employee, '2026-07-01')?->id);

        // The boundary itself: a change effective on the 1st is in force on the
        // 1st, not from the 2nd. This is the comparison that silently differed
        // between SQLite and MySQL before the date was stored as a plain date.
        $this->assertSame($new->id, $this->history->managerOn($employee, '2026-06-01')?->id);
        $this->assertSame($old->id, $this->history->managerOn($employee, '2026-05-31')?->id);
    }

    /**
     * The fallback, and the reason this feature is adoptable rather than a
     * breaking change: every employee that predates it has no history rows, and
     * there is no honest backfill. Answering null would tell the first caller
     * that nobody had a manager before this month.
     */
    public function test_an_employee_with_no_history_answers_from_the_current_columns(): void
    {
        $manager = $this->makeEmployee('EMP-MGR');
        $employee = $this->makeEmployee('EMP-2', [
            'manager_id' => $manager->id,
            'designation' => 'Accountant',
            'department' => 'Finance',
        ]);

        $this->assertSame(0, $employee->jobHistory()->count());

        $this->assertSame($manager->id, $this->history->managerOn($employee, '2020-01-01')?->id);
        $this->assertSame('Accountant', $this->history->designationOn($employee, '2020-01-01'));
        $this->assertSame('Finance', $this->history->departmentOn($employee, '2020-01-01'));

        // No row means no history, so the answer cannot vary by date. `rowOn()` is
        // how a caller that needs to know the difference finds out.
        $this->assertNull($this->history->rowOn($employee, '2020-01-01'));
    }

    /**
     * A row that exists and holds null is not the fallback: somebody at the top
     * of the org genuinely reported to nobody, and inventing the current manager
     * for that date would fabricate a reporting line that never existed.
     */
    public function test_a_history_row_with_no_manager_answers_null_rather_than_falling_back(): void
    {
        $manager = $this->makeEmployee('EMP-MGR2');
        $employee = $this->makeEmployee('EMP-3', ['manager_id' => $manager->id]);

        $this->history->record($employee, ['manager_id' => null], '2026-01-01', 'promoted to CEO');

        $this->assertNull($this->history->managerOn($employee, '2026-03-01'));
    }

    public function test_record_writes_a_row_and_updates_the_denormalised_current_value(): void
    {
        $employee = $this->makeEmployee('EMP-4');

        $row = $this->history->record($employee, ['designation' => 'Team Lead'], '2026-06-01', 'promotion');

        $this->assertDatabaseHas('employee_job_history', [
            'id' => $row->id,
            'employee_id' => $employee->id,
            'designation' => 'Team Lead',
            'reason' => 'promotion',
            'recorded_by' => $this->actor->id,
        ]);

        // Both halves, because the `employees` columns are a projection of the
        // last row and every existing query reads them.
        $this->assertSame('Team Lead', $employee->fresh()->designation);

        // Omitted columns are carried over, not blanked — a promotion still
        // records which department it happened in.
        $this->assertSame('IT', $row->department);
    }

    public function test_two_changes_on_different_dates_both_survive(): void
    {
        $employee = $this->makeEmployee('EMP-5');

        // Relative dates, all in the past, and that is not cosmetic. This test
        // originally used fixed 2026 dates that straddled the real "today", so a
        // row meant to be the newest was in fact future-dated — and it passed only
        // because record() used to project future rows immediately. Fixing that
        // broke it. Anchoring to now() keeps the test about ordering, which is
        // what it is for, and stops it drifting into the same trap as the clock
        // moves.
        $this->history->record($employee, ['designation' => 'Senior Developer'], now()->subMonths(5)->toDateString(), 'promotion');
        $this->history->record($employee, ['designation' => 'Team Lead'], now()->subMonth()->toDateString(), 'promotion');

        $this->assertSame(2, EmployeeJobHistory::where('employee_id', $employee->id)->count());

        // The earlier change is not overwritten by the later one — the failure
        // mode the `employees` columns have today, restated as an assertion.
        $this->assertSame('Senior Developer', $this->history->designationOn($employee, now()->subMonths(3)->toDateString()));
        $this->assertSame('Team Lead', $this->history->designationOn($employee, now()->toDateString()));

        // The sharp edge of the fallback, pinned here so it cannot change
        // unnoticed: a date *before* the first row has no row in force, so the
        // answer comes from the current columns — which by now say `Team Lead`,
        // not the `Developer` they actually were in February. The fallback is
        // written for employees with no history at all; for an employee who has
        // some, it answers about a period the history does not cover, and it
        // answers with the newest value rather than the nearest. Callers that
        // must tell "unknown" from "known" ask `rowOn()`, which is null here.
        $this->assertNull($this->history->rowOn($employee, now()->subMonths(9)->toDateString()));
        $this->assertSame('Team Lead', $this->history->designationOn($employee, now()->subMonths(9)->toDateString()));
    }

    /**
     * Recording late that a transfer really happened in April must not hand the
     * current values back to a state September has already superseded.
     * `employees` projects the *last row by effective date*, not the last write.
     */
    public function test_a_backdated_correction_does_not_clobber_the_current_value(): void
    {
        $employee = $this->makeEmployee('EMP-6');

        // Both in the past, for the reason given in the test above.
        $recent = now()->subMonth()->toDateString();
        $older = now()->subMonths(4)->toDateString();

        $this->history->record($employee, ['designation' => 'Team Lead'], $recent, 'promotion');
        $this->history->record($employee, ['department' => 'Platform'], $older, 'transfer, recorded late');

        // The recent promotion is still what is current, even though the older
        // row was written after it.
        $this->assertSame('Team Lead', $employee->fresh()->designation);

        // What the backdated row was actually for does land on its own date.
        $this->assertSame('Platform', $this->history->departmentOn($employee, now()->subMonths(3)->toDateString()));

        // The limits of backdating, asserted rather than left to be discovered.
        //
        // Inserting behind an existing row does not rewrite it: the promotion was
        // recorded when IT was all anyone knew, so it carried IT forward, and both
        // that row and the `employees` projection of it still say IT.
        $this->assertSame('IT', $this->history->departmentOn($employee, now()->toDateString()));
        $this->assertSame('IT', $employee->fresh()->department);

        // And the columns the caller left out of a backdated row are filled from
        // what is known *now*, because April has nothing earlier to carry over
        // from — so the April row says `Team Lead`, a title they did not hold
        // until September. Nothing in the data could say otherwise: the
        // designation they held in April was overwritten before this feature
        // existed, which is the whole problem it was built for. Backdating past
        // an existing change is therefore a correction to make explicitly —
        // pass every attribute, or record the changes in date order.
        $this->assertSame('Team Lead', $this->history->designationOn($employee, $older));
    }

    /**
     * The leaving date is a fact about the employee, kept on `employees` so
     * payroll reads it whether or not `lifecycle` is ever licensed — and
     * `is_active` stays the flag every existing query filters on.
     */
    public function test_leaving_details_persist_and_is_active_is_untouched(): void
    {
        $employee = $this->makeEmployee('EMP-7');

        $employee->update([
            'left_on' => '2026-05-31',
            'leaving_reason' => 'resigned',
            'notice_served_until' => '2026-06-30',
        ]);

        $employee->refresh();

        $this->assertSame('2026-05-31', $employee->left_on->toDateString());
        $this->assertSame('resigned', $employee->leaving_reason);
        $this->assertSame('2026-06-30', $employee->notice_served_until->toDateString());

        // Recording *when* somebody left says nothing about whether they are
        // active. Deactivation stays a separate, deliberate act.
        $this->assertTrue((bool) $employee->is_active);
    }

    /** An existing employee is not given a leaving date they never had. */
    public function test_an_inactive_employee_keeps_a_null_leaving_date(): void
    {
        $employee = $this->makeEmployee('EMP-8', ['is_active' => false]);

        $this->assertNull($employee->fresh()->left_on);
    }

    // ------------------------------------------------- the automatic writer
    //
    // Without these, the table is inert: nothing else calls record(), so history
    // would never accumulate and the fallback would answer every query for ever.

    public function test_changing_a_job_fact_records_a_row_by_itself(): void
    {
        $employee = $this->makeEmployee('EMP-9');

        $this->assertSame(0, $employee->jobHistory()->count());

        $employee->update(['designation' => 'Senior Developer']);

        $row = $employee->jobHistory()->first();

        $this->assertNotNull($row, 'A promotion left no history row.');
        $this->assertSame('Senior Developer', $row->designation);
        // A full snapshot, not a diff: the department it happened under has to be
        // on the row or reading it back says they had none.
        $this->assertSame('IT', $row->department);
        $this->assertSame($this->actor->getKey(), $row->recorded_by);
    }

    public function test_changing_something_that_is_not_a_job_fact_records_nothing(): void
    {
        // A corrected phone number has no history anybody needs, and recording one
        // would bury the three that matter.
        $employee = $this->makeEmployee('EMP-10');

        $employee->update(['name' => 'Renamed Person']);

        $this->assertSame(0, $employee->jobHistory()->count());
    }

    public function test_record_does_not_also_trigger_the_hook(): void
    {
        // record() writes the row and then projects it onto `employees`, which is a
        // save — so without the suppression this is two rows for one change, and
        // the pair recurs on every call.
        $employee = $this->makeEmployee('EMP-11');

        $this->history->record($employee, ['designation' => 'Lead'], '2026-04-01');

        $this->assertSame(1, $employee->jobHistory()->count());
    }

    public function test_several_edits_on_one_day_collapse_onto_one_row(): void
    {
        // Three corrections to a title on a Tuesday are one change to the job. Two
        // rows with the same effective_from would make rowOn() depend on its id
        // tie-break for a distinction nobody meant to draw.
        $employee = $this->makeEmployee('EMP-12');

        $employee->update(['designation' => 'Lead']);
        $employee->update(['designation' => 'Principal']);
        $employee->update(['department' => 'Platform']);

        $rows = $employee->jobHistory()->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Principal', $rows->first()->designation);
        $this->assertSame('Platform', $rows->first()->department);
    }

    // ------------------------------------------- employment type and future dates

    public function test_employment_type_is_a_job_fact_like_the_others(): void
    {
        // It sat in employee_job_history with no counterpart on `employees`, so
        // every row stored null and the column could never fill. This is the
        // assertion that it now does.
        $employee = $this->makeEmployee('EMP-14', ['employment_type' => 'probation']);

        $employee->update(['employment_type' => 'permanent']);

        $row = $employee->jobHistory()->first();

        $this->assertNotNull($row, 'Coming off probation left no history row.');
        $this->assertSame('permanent', $row->employment_type);
        $this->assertSame('permanent', $employee->fresh()->employment_type);
    }

    public function test_a_future_dated_change_does_not_apply_yet(): void
    {
        // A transfer agreed today and effective next month must not read as
        // already having happened — the difference between what is true and what
        // was decided is the whole reason this table exists.
        $employee = $this->makeEmployee('EMP-15');

        $this->history->record(
            $employee,
            ['designation' => 'Head of Engineering', 'department' => 'Platform'],
            now()->addMonth()->toDateString(),
        );

        $employee->refresh();

        $this->assertSame('Developer', $employee->designation);
        $this->assertSame('IT', $employee->department);
        $this->assertSame(1, $employee->jobHistory()->count(), 'The row itself should still be recorded.');
    }

    public function test_a_change_today_still_applies_while_a_future_one_is_pending(): void
    {
        // The bug an earlier "is any row later than this" check would have had:
        // with next month's transfer already recorded, today's promotion would
        // find that future row "later" and refuse to apply the change that is
        // actually true now.
        $employee = $this->makeEmployee('EMP-16');

        $this->history->record($employee, ['department' => 'Platform'], now()->addMonth()->toDateString());
        $this->history->record($employee, ['designation' => 'Lead'], now()->toDateString());

        $employee->refresh();

        $this->assertSame('Lead', $employee->designation, 'Today\'s change was withheld by a pending future one.');
        $this->assertSame('IT', $employee->department, 'Next month\'s transfer leaked into today.');
    }

    /*
     * The next two exercise `applyDueChanges()` rather than
     * `artisan('employees:apply-job-changes')`, and that is a limit of the suite
     * rather than a preference. The command is TenantAware, which switches tenant
     * databases; this suite runs one in-memory SQLite database with the tenant
     * migrations loaded into it, so spatie throws
     * `tenantConnectionIsEmptyOrEqualsToLandlordConnection` before the handler is
     * reached. `docs/new-module-checklist.md` §12 states the constraint: per-company
     * command fan-out is not coverable here and is verified by hand.
     *
     * What that leaves untested is the fan-out itself — that the command reaches
     * every tenant and skips companies without the module. The logic it fans out
     * to is what these cover.
     */

    public function test_a_change_is_applied_once_its_date_arrives(): void
    {
        $employee = $this->makeEmployee('EMP-17');

        $this->history->record($employee, ['designation' => 'Head of Engineering'], now()->addDays(3)->toDateString());

        $this->assertSame('Developer', $employee->fresh()->designation);

        $this->travel(4)->days();

        $this->assertSame(['EMP-17'], $this->history->applyDueChanges());
        $this->assertSame('Head of Engineering', $employee->fresh()->designation);
    }

    public function test_applying_due_changes_is_idempotent(): void
    {
        // It runs every day over employees whose rows mostly applied months ago,
        // so a settled company must be touched not at all — and a second run must
        // never record a history row of its own.
        $employee = $this->makeEmployee('EMP-18');
        $this->history->record($employee, ['designation' => 'Lead'], now()->toDateString());

        // Already in force and already projected, so the first sweep has nothing
        // to do either — an empty return is the assertion that it is a no-op.
        $this->assertSame([], $this->history->applyDueChanges());
        $this->assertSame([], $this->history->applyDueChanges());

        $this->assertSame('Lead', $employee->fresh()->designation);
        $this->assertSame(1, $employee->jobHistory()->count());
    }

    public function test_an_employee_with_no_history_is_never_touched(): void
    {
        // The sweep must not write to the entire existing employee base, whose
        // columns are the only truth there is.
        $this->makeEmployee('EMP-19');

        $this->assertSame([], $this->history->applyDueChanges());
    }

    public function test_a_manager_change_is_captured_and_readable_afterwards(): void
    {
        // The whole point, through the automatic path rather than record().
        $first = $this->makeEmployee('MGR-A');
        $second = $this->makeEmployee('MGR-B');
        $employee = $this->makeEmployee('EMP-13', ['manager_id' => $first->getKey()]);

        $employee->update(['manager_id' => $second->getKey()]);

        $this->assertSame(
            $second->getKey(),
            $this->history->managerOn($employee, now()->toDateString())?->getKey(),
        );
    }
}
