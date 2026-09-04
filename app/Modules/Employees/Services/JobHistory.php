<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeJobHistory;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * "What was true about this employee's job on date X."
 *
 * The question this exists to answer is who could approve for someone at a past
 * date. Leave approval routes through `employees.manager_id` via
 * `App\Support\EmployeeAccess`, and that column is overwritten in place — a leave
 * request approved in March keeps its `decided_by`, so the approval survives, but
 * the *authority* behind it does not. Every approval chain the HRMS plan adds
 * inherits the same hole. See docs/hrms-plan.md §3.
 *
 * ## "As at date X" means: the latest row with `effective_from <= X`
 *
 * Not the row whose span contains X — there are no spans. A row opens a period
 * and the next row closes it, so a single ordered lookup answers the question and
 * no two rows can disagree about a date the way a start/end pair can.
 *
 * ## THE FALLBACK — read this before calling anything here
 *
 * When there is **no** row at or before X, every read here falls back to the
 * current value on `employees` and never returns null for that reason.
 *
 * This is not a convenience, it is what makes the service adoptable. Every
 * employee that existed before this feature shipped has zero history rows, and
 * there is no backfill that could invent them honestly — we do not know when past
 * promotions happened. Without the fallback, `managerOn()` would answer null for
 * the entire existing employee base, and the first caller to trust it would
 * silently decide that nobody had a manager before this month. With it, the
 * answer for an employee with no history is "the manager they have now", which is
 * the best information that exists and is exactly what the calling code does
 * today.
 *
 * Two consequences, stated plainly so nobody discovers them in production:
 *
 *  - For an employee with no history rows, the answer is the same for every date.
 *    That is not history, it is the absence of it.
 *  - The same rule applies to a date *before* an employee's first row, where it
 *    is sharper: the answer is the current value, which is the newest one rather
 *    than the nearest. Asking about January when the first row is dated March
 *    gets today's manager, not the one they had in January.
 *
 * Callers that must tell "unknown" from "known" ask `rowOn()` for the row itself
 * and check for null; both cases above return null there. Everything else gets an
 * answer, because a null from `managerOn()` has to keep meaning "reported to
 * nobody" for the approval chains that read it.
 *
 * A row that exists but holds null is exactly that different thing, and is
 * returned as null: an employee at the top of the org genuinely had no manager on
 * that date, and falling back to the current value there would fabricate a
 * reporting line that never existed.
 */
class JobHistory
{
    /** The manager as at that date, or null if they reported to nobody then. */
    public function managerOn(Employee $employee, string|Carbon $date): ?Employee
    {
        $row = $this->rowOn($employee, $date);

        if ($row === null) {
            return $employee->manager; // fallback — see the class docblock
        }

        return $row->manager_id ? Employee::find($row->manager_id) : null;
    }

    public function designationOn(Employee $employee, string|Carbon $date): ?string
    {
        return $this->valueOn($employee, $date, 'designation');
    }

    public function departmentOn(Employee $employee, string|Carbon $date): ?string
    {
        return $this->valueOn($employee, $date, 'department');
    }

    /**
     * A plain column as at that date, from the history row if there is one and
     * from the employee's current value if there is not.
     */
    private function valueOn(Employee $employee, string|Carbon $date, string $column): ?string
    {
        $row = $this->rowOn($employee, $date);

        return $row === null
            ? $employee->getAttribute($column) // fallback — see the class docblock
            : $row->getAttribute($column);
    }

    /**
     * The history row in force on that date, or null when the employee has none
     * that early.
     *
     * Public because null here is the only way a caller can tell "no manager"
     * apart from "no history"; the typed readers above deliberately collapse the
     * distinction.
     *
     * The tie-break on `id` matters: two changes recorded with the same
     * `effective_from` — a correction entered right after the row it corrects —
     * must resolve to the one entered later, deterministically, on every database.
     */
    public function rowOn(Employee $employee, string|Carbon $date): ?EmployeeJobHistory
    {
        return EmployeeJobHistory::query()
            ->where('employee_id', $employee->getKey())
            ->where('effective_from', '<=', $this->asDate($date))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Every role the employee has held, oldest first — what an experience letter lists.
     *
     * Here rather than a query in the letter service, for the reason `EmployeeJobHistory`'s docblock gives:
     * reads of that table go through this class so the ordering and the no-history case are decided once.
     * The same `effective_from`/`id` tie-break as `rowOn()`, so a correction entered after the row it
     * corrects reads in the same order everywhere.
     *
     * **Empty is the ordinary case, not an error.** History is written from the day the feature shipped, so
     * an employee hired before it has nothing here — a letter falls back to the current designation on the
     * record, which is what the columns are a projection of.
     *
     * @return \Illuminate\Support\Collection<int, EmployeeJobHistory>
     */
    public function rolesFor(Employee $employee): Collection
    {
        return EmployeeJobHistory::query()
            ->where('employee_id', $employee->getKey())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
    }

    /**
     * Records a job change and moves the current values on `employees` to match.
     *
     * Both writes, always, because the `employees` columns are a projection of the
     * last row — every existing query reads them and none of them should have to
     * learn about history to keep working. Two rows and two truths is the failure
     * this whole feature exists to prevent, so they are not separable calls.
     *
     * `$attributes` takes any of `designation`, `department`, `manager_id`,
     * `employment_type`. Anything omitted is carried over rather than left null: a
     * row is a full snapshot of the job as at that date, so a promotion that
     * changes only the title must still record which department and manager it
     * happened under, or reading the row back would say they had neither.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(
        Employee $employee,
        array $attributes,
        string|Carbon $effectiveFrom,
        ?string $reason = null,
    ): EmployeeJobHistory {
        $effectiveFrom = $this->asDate($effectiveFrom);

        // What was already true on that date, which is what the omitted columns
        // carry over. For the ordinary case — a change effective now — this is the
        // previous row and holds exactly the employee's current values, so the two
        // sources agree. It only diverges when backdating, and there this is the
        // right one: a transfer recorded late snapshots the title held *then*
        // rather than the one a later promotion has since given them.
        //
        // Backdating *behind every existing row* is the case it cannot save, and
        // the fall-through below is knowingly imperfect: with nothing earlier to
        // copy, the omitted columns take today's values, which may postdate the
        // row being written. No data exists that would say otherwise — the older
        // values were overwritten before this table existed, which is the problem
        // it was built to end. Pass every attribute when backdating that far.
        $inForce = $this->rowOn($employee, $effectiveFrom);

        $snapshot = [];
        foreach (self::TRACKED as $column) {
            $snapshot[$column] = match (true) {
                array_key_exists($column, $attributes) => $attributes[$column],
                $inForce !== null => $inForce->getAttribute($column),
                // No history that early: fall back to the live record, exactly as
                // the readers do. `employment_type` has no column on `employees`
                // yet and so reads null here until the employee form grows one —
                // correct, not a bug: the fact starts life in this table.
                default => $employee->getAttribute($column),
            };
        }

        $row = EmployeeJobHistory::create($snapshot + [
            'employee_id' => $employee->getKey(),
            'effective_from' => $effectiveFrom,
            'reason' => $reason,
            // A landlord user id (`users` is a shared table), nullable so a
            // system-initiated change is not attributed to whoever happened to be
            // signed in when a queue worker ran.
            'recorded_by' => auth()->id(),
        ]);

        $this->syncCurrentValues($employee, $row);

        return $row;
    }

    /**
     * Snapshot what the employee's job is *right now*, effective today.
     *
     * The counterpart to record(): record() is told what to change and moves the
     * employee to match, while this is told the employee has already changed and
     * catches up. `Employee::booted()` calls it from an `updated` hook, so a job
     * fact edited anywhere — the form, an approved change request, the importer,
     * tinker — leaves a row behind.
     *
     * Without this the table is inert. Nothing else calls record(), so history
     * would never accumulate and the fallback would answer every query for ever
     * — a feature that reads correctly and records nothing.
     *
     * No sync back: the employee already holds these values, which is why it was
     * called. Same-day edits collapse onto one row per day by design — three
     * corrections to a title on a Tuesday are one change to the job, and
     * `rowOn()`'s id tie-break resolves them to the last one written.
     */
    public function captureCurrent(Employee $employee): EmployeeJobHistory
    {
        $today = Carbon::now()->toDateString();

        $existingToday = EmployeeJobHistory::query()
            ->where('employee_id', $employee->getKey())
            ->where('effective_from', $today)
            ->orderByDesc('id')
            ->first();

        $snapshot = [];
        foreach (self::TRACKED as $column) {
            $snapshot[$column] = $employee->getAttribute($column);
        }

        if ($existingToday !== null) {
            $existingToday->update($snapshot);

            return $existingToday;
        }

        return EmployeeJobHistory::create($snapshot + [
            'employee_id' => $employee->getKey(),
            'effective_from' => $today,
            'reason' => null,
            'recorded_by' => auth()->id(),
        ]);
    }

    /** Job facts a history row snapshots. */
    private const TRACKED = ['designation', 'department', 'manager_id', 'employment_type'];

    /**
     * The subset of TRACKED that `employees` also carries, and so the subset the
     * denormalised current state can hold.
     *
     * All four, since `add_employment_type_to_employees` gave the fourth a
     * counterpart. Before that this list was three and every history row stored a
     * null `employment_type`, because there was nothing to snapshot it from.
     */
    private const DENORMALISED = ['designation', 'department', 'manager_id', 'employment_type'];

    /**
     * Copies the row onto `employees`, but only when it is the newest one.
     *
     * A backdated correction — recording in August that a transfer really happened
     * in March — must not overwrite the current designation with a value that was
     * superseded in June. `employees` holds a projection of the *last* row, so it
     * only moves when the row just written is that last row.
     *
     * A **future**-dated row does not move it either, and that is the other half:
     * a transfer agreed today and effective next month must not read as already
     * having happened. `employees:apply-job-changes` runs daily and moves the
     * columns on the day the row applies — see App\Modules\Employees\Console\Commands\ApplyDueJobChanges.
     */
    private function syncCurrentValues(Employee $employee, EmployeeJobHistory $row): void
    {
        // One question, asked the same way the readers ask it: is this row the one
        // in force *today*?
        //
        // That single check covers both refusals. A backdated row is not in force
        // (a later one is), and a future-dated row is not in force (rowOn only
        // looks at `effective_from <= today`, so it returns an earlier row or
        // none). An earlier version asked "is any row later than this one",
        // which got the future case exactly backwards: writing today's change
        // while a transfer was already recorded for next month would find the
        // future row "later" and refuse to apply the change that is true now.
        if ($this->rowOn($employee, Carbon::now())?->getKey() !== $row->getKey()) {
            return;
        }

        $current = [];
        foreach (self::DENORMALISED as $column) {
            $current[$column] = $row->getAttribute($column);
        }

        // Two bypasses, for two different interceptions.
        //
        // withoutApprovalRouting: the history row *is* the authority for this
        // write. Without it, an employee changing their own record would have it
        // intercepted into a pending EmployeeChangeRequest — and since none of
        // these columns is in `EmployeeChangeRequest::ALLOWED_FIELDS`, the edit
        // would be dropped outright while the history row stayed, leaving the two
        // disagreeing.
        //
        // withoutJobHistory: this save is the projection of a row that was just
        // written, so Employee's `updated` hook would file a duplicate row for the
        // same change — and since capturing saves nothing, the pair would recur on
        // every record() call.
        Employee::withoutApprovalRouting(fn () => Employee::withoutJobHistory(
            fn () => $employee->forceFill($current)->save(),
        ));
    }

    /**
     * Bring every employee's denormalised columns up to the row in force today.
     *
     * What makes future-dating real. `record()` refuses to project a row that has
     * not started, so a transfer agreed in August and effective in September sits
     * in the table until September — this is what moves it, run daily by
     * `employees:apply-job-changes`.
     *
     * Idempotent, and it has to be: it runs every day over employees whose rows
     * mostly applied long ago. An employee already matching their in-force row is
     * not written at all, so a daily sweep over a settled company touches nothing
     * and logs nothing.
     *
     * @return array<int, string> The employee_id of each record actually changed.
     */
    public function applyDueChanges(): array
    {
        $applied = [];
        $today = Carbon::now();

        // Only employees who have any history at all — for everybody else the
        // columns are the only truth there is and there is nothing to apply.
        $employees = Employee::query()
            ->whereIn(
                'id',
                EmployeeJobHistory::query()->select('employee_id')->distinct(),
            )
            ->get();

        foreach ($employees as $employee) {
            $row = $this->rowOn($employee, $today);

            if ($row === null) {
                continue;
            }

            $changes = [];
            foreach (self::DENORMALISED as $column) {
                if ($employee->getAttribute($column) != $row->getAttribute($column)) {
                    $changes[$column] = $row->getAttribute($column);
                }
            }

            if ($changes === []) {
                continue;
            }

            // Same two bypasses as syncCurrentValues, for the same two reasons:
            // the row is the authority, and this save is its projection rather
            // than a new change to record.
            Employee::withoutApprovalRouting(fn () => Employee::withoutJobHistory(
                fn () => $employee->forceFill($changes)->save(),
            ));

            $applied[] = $employee->employee_id;
        }

        return $applied;
    }

    /** Accepts either form callers have to hand and normalises to a date string. */
    private function asDate(string|Carbon $date): string
    {
        return Carbon::parse($date)->toDateString();
    }
}
