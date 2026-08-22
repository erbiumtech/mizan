<?php

namespace App\Modules\ConstructionField\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\ProgrammeActivity;
use App\Modules\ConstructionField\Models\ProgrammeActivityPredecessor;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The programme — `docs/construction-management-plan.md` §13.
 *
 * **There is no scheduler in this class and there never will be.** §13: "store the programme; never solve it." No
 * forward pass, no backward pass, no float calculation, no critical-path solver, no resource levelling. Every date this
 * service reads is a stored column, and `is_critical` and `total_float_days` are written only by whoever imported them.
 *
 * The reasoning is worth restating because the temptation is permanent: building CPM is not the expensive part, keeping
 * it in step with P6 is. The accepted programme lives in the planner's tool because that is what was submitted, and a
 * second scheduler here that disagreed with it would manufacture a figure the quantity surveyor quotes in a claim and
 * the planner does not recognise — invisible until an adjudication.
 *
 * What this service does, all of which needs stored dates and nothing else:
 *
 *  - **`lookAhead()`** — what is planned to start in a window, and what has not started that should have.
 *  - **`blockedBy()`** — the predecessors of an activity that are not finished. The links are read; no date is derived.
 *  - **`ldExposure()`** — days a priced contract milestone is late against the *accepted* programme, less the extension
 *    of time actually awarded. **This is the figure a programme is worth storing for**, and it exists only because
 *    baseline and planned dates are kept apart and delay events record what was determined rather than claimed.
 *  - **`progress()`** — records actuals and percent complete against a data date, refusing the combinations that are
 *    not facts.
 */
class ProgrammeService
{
    /**
     * Add an activity.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(Job $job, array $attributes): ProgrammeActivity
    {
        foreach (['code' => 'an activity id', 'name' => 'a name'] as $field => $what) {
            if (trim((string) ($attributes[$field] ?? '')) === '') {
                throw new InvalidArgumentException(
                    "An activity needs {$what}. The programme is read against the planner's own printout, and a row "
                    .'that cannot be matched to a line on it is a row nobody can use.'
                );
            }
        }

        $this->guardDates($attributes);

        return TenantTransaction::run(fn (): ProgrammeActivity => ProgrammeActivity::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
        ])));
    }

    /**
     * Edit one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(ProgrammeActivity $activity, array $attributes): ProgrammeActivity
    {
        $this->guardDates(array_merge($activity->only([
            'baseline_start', 'baseline_finish', 'planned_start', 'planned_finish', 'actual_start', 'actual_finish',
        ]), $attributes));

        $activity->update($attributes);

        return $activity->refresh();
    }

    /**
     * **Record progress as at a data date.**
     *
     * Three refusals, each of which is a combination that is not a fact rather than a policy:
     *
     *  - **A finish before a start.** Not arguable.
     *  - **100% complete with no actual finish, or an actual finish with less than 100%.** §13 wants percent complete
     *    *and* actual dates because a schedule index needs one and a milestone needs the other — but a row saying the
     *    work is finished and never finished is the state that makes both useless.
     *  - **Progress with no data date.** "40% as at the 1st" and "40% as at the 30th" are different facts, and a
     *    percentage without one cannot be compared with last month's.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function progress(ProgrammeActivity $activity, array $attributes): ProgrammeActivity
    {
        if (blank($attributes['data_date'] ?? $activity->data_date)) {
            throw new InvalidArgumentException(
                'Progress needs a data date. "40% complete" as at the 1st and as at the 30th are different facts, and '
                .'without the date neither can be compared with last month.'
            );
        }

        $merged = array_merge($activity->only(['actual_start', 'actual_finish', 'percent_complete']), $attributes);

        $this->guardDates($merged);

        $percent = (float) ($merged['percent_complete'] ?? 0);
        $finished = filled($merged['actual_finish'] ?? null);

        if ($finished && $percent < 100.0) {
            throw new InvalidArgumentException(
                'An activity with an actual finish is complete, so it cannot be at '.$percent.'%. Clear the finish '
                .'date or set it to 100 — a row that says the work is both finished and unfinished makes the schedule '
                .'index and the milestone both useless.'
            );
        }

        if (! $finished && $percent >= 100.0) {
            throw new InvalidArgumentException(
                'An activity at 100% needs an actual finish date. Percent complete drives the schedule index and the '
                .'date drives the milestone; one without the other is half a fact.'
            );
        }

        $activity->update($attributes);

        return $activity->refresh();
    }

    /**
     * Store a logical link.
     *
     * **Nothing computes a date from this.** What it buys is a round-trip that does not corrupt the planner's file, and
     * `blockedBy()`.
     */
    public function addPredecessor(
        ProgrammeActivity $activity,
        ProgrammeActivity $predecessor,
        string $relationship = ProgrammeActivityPredecessor::FINISH_TO_START,
        int $lagDays = 0,
    ): ProgrammeActivityPredecessor {
        if ($predecessor->getKey() === $activity->getKey()) {
            throw new InvalidArgumentException('An activity cannot precede itself.');
        }

        if ($predecessor->job_id !== $activity->job_id) {
            throw new InvalidArgumentException(
                'Both activities have to be on the same job. A link across jobs is a link no imported programme can '
                .'contain, so it is a mistake rather than a feature.'
            );
        }

        if (! array_key_exists($relationship, ProgrammeActivityPredecessor::RELATIONSHIPS)) {
            throw new InvalidArgumentException("{$relationship} is not a relationship P6 exports.");
        }

        return $activity->predecessors()->create([
            'predecessor_activity_id' => $predecessor->getKey(),
            'relationship' => $relationship,
            'lag_days' => $lagDays,
        ]);
    }

    /**
     * **What is stopping this activity** — its predecessors that are not finished.
     *
     * The links are read and nothing is calculated. A look-ahead that says "cladding cannot start" is worth little; one
     * that says "and the two activities in front of it have not started either" is a conversation.
     *
     * @return Collection<int, ProgrammeActivity>
     */
    public function blockedBy(ProgrammeActivity $activity): Collection
    {
        $ids = ProgrammeActivityPredecessor::query()
            ->where('activity_id', $activity->getKey())
            ->pluck('predecessor_activity_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return ProgrammeActivity::query()
            ->whereIn('id', $ids)
            ->incomplete()
            ->orderBy('code')
            ->get();
    }

    /**
     * **The look-ahead**: what is planned to start in a window.
     *
     * Read off `planned_start`, a stored column. Deriving it by walking predecessors would be a forward pass in all but
     * name, which is what §13 forbids.
     *
     * @return Collection<int, ProgrammeActivity>
     */
    public function lookAhead(Job $job, ?string $from = null, int $days = 21): Collection
    {
        $start = Carbon::parse($from ?? now())->startOfDay();

        return ProgrammeActivity::query()
            ->forJobTree($job)
            ->notStarted()
            ->startingBetween($start->toDateString(), $start->copy()->addDays($days)->toDateString())
            ->orderBy('planned_start')
            ->get();
    }

    /**
     * Should have started and has not — the commonest early signal, and it needs no network.
     *
     * @return Collection<int, ProgrammeActivity>
     */
    public function lateToStart(Job $job, ?string $asAt = null): Collection
    {
        $date = Carbon::parse($asAt ?? now())->startOfDay()->toDateString();

        return ProgrammeActivity::query()
            ->forJobTree($job)
            ->notStarted()
            ->whereNotNull('planned_start')
            ->whereDate('planned_start', '<', $date)
            ->orderBy('planned_start')
            ->get();
    }

    /**
     * The contract's dated milestones, in order.
     *
     * @return Collection<int, ProgrammeActivity>
     */
    public function milestones(Job $job): Collection
    {
        return ProgrammeActivity::query()
            ->forJobTree($job)
            ->milestones()
            ->with('delayEvents')
            ->orderBy('baseline_finish')
            ->get();
    }

    /**
     * **The liquidated-damages exposure** — the figure §13 is worth its length for.
     *
     * For every priced contract milestone: days late against the *accepted* programme, less the extension of time
     * actually awarded on its delay events. Damages accrue against that remainder, and nothing else in the application
     * is watching it — the same silence the diary's unnotified event and the RFI's unnotified time impact live in, one
     * document further along.
     *
     * `ld_applies` only, because §13 keeps that flag separate from `is_contract_milestone` on purpose: a contract names
     * dates it does not charge for, and levying damages against a planner's marker is the failure that separation
     * prevents.
     *
     * @return array<int, array{activity: ProgrammeActivity, late_days: int, awarded_days: int, unexcused_days: int}>
     */
    public function ldExposure(Job $job, ?string $asAt = null): array
    {
        return ProgrammeActivity::query()
            ->forJobTree($job)
            ->milestones()
            ->where('ld_applies', true)
            ->with('delayEvents')
            ->orderBy('baseline_finish')
            ->get()
            ->map(fn (ProgrammeActivity $activity): array => [
                'activity' => $activity,
                'late_days' => $activity->daysLateAgainstBaseline($asAt),
                'awarded_days' => $activity->awardedDays(),
                'unexcused_days' => $activity->unexcusedLateDays($asAt),
            ])
            ->filter(fn (array $row): bool => $row['unexcused_days'] > 0)
            ->values()
            ->all();
    }

    /**
     * Activities behind where the accepted programme says they should be.
     *
     * Filtered in PHP: the comparison is percent complete against elapsed baseline time, which is a date difference
     * against two per-row columns — and expressing that in SQL means a dialect-specific date function. Phase 9e
     * recorded the rule after making the mistake twice.
     *
     * @return Collection<int, ProgrammeActivity>
     */
    public function behindBaseline(Job $job, ?string $asAt = null): Collection
    {
        return ProgrammeActivity::query()
            ->forJobTree($job)
            ->incomplete()
            ->whereNotNull('baseline_start')
            ->whereNotNull('baseline_finish')
            ->get()
            ->filter(fn (ProgrammeActivity $activity): bool => $activity->isBehindBaseline($asAt))
            ->values();
    }

    /**
     * Where the critical path on this job came from, per source.
     *
     * §13's argument made visible: criticality and float are somebody else's numbers, so a screen quoting them can say
     * which tool produced them — and a job whose critical activities are all `manual` is a job where somebody typed a
     * critical path, which is worth knowing before it reaches a claim.
     *
     * @return array<string, int>
     */
    public function criticalBySource(Job $job): array
    {
        return ProgrammeActivity::query()
            ->forJobTree($job)
            ->where('is_critical', true)
            ->get()
            ->groupBy('source')
            ->map(fn (Collection $activities): int => $activities->count())
            ->all();
    }

    /**
     * The date pairs that are not facts.
     *
     * Deliberately narrow: an actual finish before an actual start, or a baseline finish before its start. Everything
     * else a programme does — a planned start before the baseline, an activity finishing before it was due — is
     * ordinary and often the point.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function guardDates(array $attributes): void
    {
        foreach ([
            ['actual_start', 'actual_finish', 'An activity cannot finish before it started.'],
            ['baseline_start', 'baseline_finish', 'A baseline cannot finish before it starts.'],
            ['planned_start', 'planned_finish', 'A planned finish cannot precede its planned start.'],
        ] as [$startKey, $finishKey, $message]) {
            $start = $attributes[$startKey] ?? null;
            $finish = $attributes[$finishKey] ?? null;

            if (blank($start) || blank($finish)) {
                continue;
            }

            if (Carbon::parse($finish)->lt(Carbon::parse($start))) {
                throw new InvalidArgumentException($message);
            }
        }
    }
}
