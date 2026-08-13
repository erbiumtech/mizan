<?php

namespace App\Modules\Performance\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Mpr\Models\MPR;
use App\Modules\Performance\Models\Review;
use App\Modules\Performance\Models\ReviewCycle;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Opening a cycle, and reading what already exists as evidence.
 *
 * **The MPR integration is the whole of the Performance module's cleverness, and it is
 * deliberately small.** This application already has a monthly self-report with
 * month-over-month comparison. A review cycle *reads* the MPRs in its period rather than
 * asking somebody to write the same thing again — which is worth more than another
 * free-text box, and is the only integration §4.5 asks for.
 *
 * Guarded on `mpr`: without it a cycle simply has no evidence attached, which is the
 * state every company was in before.
 */
class ReviewCycleService
{
    /**
     * Open a cycle and raise a review for every active employee.
     *
     * The reviewer defaults to the employee's manager, resolved from the live
     * `manager_id`. Deliberately stamped rather than looked up later: reporting lines
     * move, and a review from March should keep saying who was actually asked to write
     * it — the same reasoning behind `employee_job_history`.
     *
     * @return int reviews raised
     */
    public function open(ReviewCycle $cycle): int
    {
        if ($cycle->isClosed()) {
            throw new InvalidArgumentException('This cycle is closed. Reviews cannot be raised against it.');
        }

        $raised = 0;

        Employee::query()->where('is_active', true)->cursor()->each(
            function (Employee $employee) use ($cycle, &$raised): void {
                $review = Review::firstOrNew([
                    'review_cycle_id' => $cycle->getKey(),
                    'employee_id' => $employee->getKey(),
                ]);

                if ($review->exists) {
                    return;
                }

                $review->reviewer_employee_id = $employee->manager_id;
                $review->save();

                $raised++;
            }
        );

        $cycle->update(['status' => ReviewCycle::STATUS_OPEN]);

        return $raised;
    }

    /**
     * The MPRs an employee filed inside a cycle's period.
     *
     * Read-only, and read rather than copied: an MPR is the employee's own account of
     * their month, and duplicating it into the review would create a second version that
     * could drift from what they actually wrote.
     *
     * MPR keys on `user_id` rather than `employee_id`, which is why this goes through the
     * employee's user and returns nothing for somebody without a login.
     *
     * @return Collection<int, MPR>
     */
    public function evidenceFor(Review $review): Collection
    {
        if (! modules()->enabled('mpr') || ! $review->employee?->user_id) {
            return new Collection;
        }

        return MPR::query()
            ->where('user_id', $review->employee->user_id)
            ->whereBetween('created_at', [
                $review->cycle->period_start->startOfDay(),
                $review->cycle->period_end->endOfDay(),
            ])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Share a review with the person it is about.
     *
     * The step that makes it visible to them. Submitted is not shared: a written review is
     * a draft until a manager decides, and showing it earlier would make honest drafting
     * impossible.
     */
    public function share(Review $review): Review
    {
        if ($review->submitted_at === null) {
            throw new InvalidArgumentException('An unsubmitted review has nothing to share yet.');
        }

        $review->update([
            'status' => Review::STATUS_SHARED,
            'shared_at' => now(),
        ]);

        return $review;
    }

    /**
     * A rating does NOT change pay, and this method is where somebody would be tempted to
     * make it.
     *
     * It returns a *suggestion* — a figure for a human to consider and save as a new
     * EmployeeSetting themselves. Nothing here writes to a package. §4.5: wiring a rating
     * to a salary makes the appraisal a payroll instruction, and the first disputed rating
     * becomes a payroll incident.
     *
     * @return array{current: ?float, suggested: ?float, note: string}
     */
    public function suggestedIncrement(Review $review): array
    {
        $note = 'A suggestion only. Nothing is applied: a rating is an opinion, and a '
            .'salary is a versioned package somebody approves. Save a new employee setting '
            .'if you agree with it.';

        if (! modules()->enabled('payroll') || $review->final_rating === null) {
            return ['current' => null, 'suggested' => null, 'note' => $note];
        }

        $setting = \App\Modules\Employees\Models\EmployeeSetting::query()
            ->where('employee_id', $review->employee_id)
            ->orderByDesc('start_date')
            ->first();

        if (! $setting) {
            return ['current' => null, 'suggested' => null, 'note' => $note];
        }

        // A flat scale, and openly a placeholder for whatever a company decides. It is
        // config rather than code precisely so nobody mistakes it for a policy this
        // application holds an opinion about.
        $scale = (array) config('performance.increment_scale', [5 => 0.15, 4 => 0.10, 3 => 0.05]);
        $percent = (float) ($scale[$review->final_rating] ?? 0);

        return [
            'current' => (float) $setting->basic_wage,
            'suggested' => round((float) $setting->basic_wage * (1 + $percent), 2),
            'note' => $note,
        ];
    }
}
