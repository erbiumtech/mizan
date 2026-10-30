<?php

namespace App\Modules\Performance\Services;

use App\Modules\Core\Models\User;
use App\Modules\Core\Services\HolidayCalendar;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\MeritEntry;
use App\Modules\Performance\Models\RecurringTask;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Support\Contracts\WorkingDayCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Recurring tasks, day by day — docs/saas-launch-plan.md §8.2.
 *
 * roll() makes today's occurrences and marks yesterday's misses; complete() and
 * excuse() are the two things a person does to one. Working days are asked of
 * the pattern and the calendar separately, because the resolver's own docblock
 * says combining them is how a caller ends up asking half.
 */
class TaskRoller
{
    public function __construct(
        private readonly MeritLedger $ledger,
        private readonly HolidayCalendar $holidays,
        private readonly WorkingDayCalendar $workingDays,
    ) {}

    /**
     * Today's occurrences, and yesterday's misses — idempotent on both.
     *
     * @return array{created: int, missed: int}
     */
    public function roll(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $created = 0;

        foreach (RecurringTask::query()->active()->get() as $task) {
            if (! $task->isDueOn($today)) {
                continue;
            }

            foreach ($task->assignees() as $employee) {
                // Not before they join nor after they leave: `is_active` alone handed a hire
                // with a future joining date a missed task, and its penalty, every day until then.
                if ($employee->date_of_joining?->gt($today) || $employee->left_on?->lt($today)) {
                    continue;
                }

                if ($task->working_days_only && ! $this->isWorkingDay($employee, $today)) {
                    continue;
                }

                // whereDate, not firstOrCreate's equality: the date cast stores a
                // time of day, and an equality on the bare date would never match
                // — then insert into the unique key, and fail.
                $exists = TaskOccurrence::query()
                    ->where('recurring_task_id', $task->getKey())
                    ->where('employee_id', $employee->getKey())
                    ->whereDate('due_on', $today->toDateString())
                    ->exists();

                if ($exists) {
                    continue;
                }

                TaskOccurrence::query()->create([
                    'recurring_task_id' => $task->getKey(),
                    'employee_id' => $employee->getKey(),
                    'due_on' => $today->toDateString(),
                    'due_at' => $task->dueAtOn($today),
                ]);
                $created++;
            }
        }

        return ['created' => $created, 'missed' => $this->markMisses(now())];
    }

    /**
     * Every open occurrence past its due time plus the task's grace is missed,
     * once, with one merit debit when the task carries points.
     */
    public function markMisses(Carbon $now): int
    {
        $missed = 0;

        $open = TaskOccurrence::query()->open()->with('task')->where('due_at', '<=', $now)->get();

        foreach ($open as $occurrence) {
            $task = $occurrence->task;
            $deadline = $occurrence->due_at->copy()->addHours((int) ($task?->grace_hours ?? 0));

            if ($deadline->greaterThan($now)) {
                continue;
            }

            $entry = null;

            if ($task && $task->penalty_points > 0) {
                $entry = $this->ledger->record(
                    $occurrence->employee_id,
                    -$task->penalty_points,
                    "Missed: {$task->title} (due {$occurrence->due_at->format('d M H:i')})",
                    MeritEntry::SOURCE_TASK,
                    $occurrence->getKey(),
                    occurredOn: $occurrence->due_on->toDateString(),
                );
            }

            $occurrence->forceFill(['missed_at' => $now, 'merit_entry_id' => $entry?->getKey()])->save();
            $missed++;
        }

        return $missed;
    }

    public function complete(TaskOccurrence $occurrence, ?string $note = null, ?User $by = null): TaskOccurrence
    {
        if (! $occurrence->isOpen()) {
            throw new InvalidArgumentException("This task is {$occurrence->status()} and cannot be completed.");
        }

        $occurrence->forceFill(['completed_at' => now(), 'completed_note' => $note])->save();

        return $occurrence;
    }

    /** A manager's excuse: the miss stays on the row; the debit is reversed. */
    public function excuse(TaskOccurrence $occurrence, User $by, string $reason): TaskOccurrence
    {
        if ($occurrence->status() !== TaskOccurrence::STATUS_MISSED) {
            throw new InvalidArgumentException("Only a missed task is excused (this one is {$occurrence->status()}).");
        }

        if ($occurrence->meritEntry) {
            $this->ledger->reverse($occurrence->meritEntry, "Excused: {$reason}", $by);
        }

        $occurrence->forceFill(['excused_by' => $by->getKey(), 'excused_at' => now(), 'excused_reason' => $reason])->save();

        return $occurrence;
    }

    /**
     * The month's unexcused misses with a rupee figure, per employee — what
     * `tasks:settle` reports for the payroll clerk.
     *
     * @return Collection<int, array{employee: Employee, misses: int, amount: float}>
     */
    public function monthlyPenalties(Carbon $month): Collection
    {
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        return TaskOccurrence::query()
            ->missedUnexcused()
            ->whereDate('due_on', '>=', $from)
            ->whereDate('due_on', '<=', $to)
            ->with(['task', 'employee.user'])
            ->get()
            ->filter(fn (TaskOccurrence $o): bool => (float) ($o->task?->penalty_amount ?? 0) > 0)
            ->groupBy('employee_id')
            ->map(fn (Collection $rows): array => [
                'employee' => $rows->first()->employee,
                'misses' => $rows->count(),
                'amount' => round((float) $rows->sum(fn (TaskOccurrence $o): float => (float) $o->task->penalty_amount), 2),
            ])
            ->values();
    }

    private function isWorkingDay(Employee $employee, Carbon $date): bool
    {
        if ($this->holidays->isHoliday($date, $employee->location_id)) {
            return false;
        }

        return $this->workingDays->isWorkingDay($employee->getKey(), $date);
    }
}
