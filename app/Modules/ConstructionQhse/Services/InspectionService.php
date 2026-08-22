<?php

namespace App\Modules\ConstructionQhse\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\InspectionCheck;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Inspections and hold points — `docs/construction-management-plan.md` §17.1.
 *
 * Five rules live here, and the first two are what make an ITP a document rather than a formality.
 *
 *  - **The point type is snapshotted at request time.** An ITP gets revised and a hold point becomes a witness point; an
 *    inspection carried out under the old plan was carried out under the old rules. The same reasoning §8 freezes
 *    retention terms with and §13 freezes notice days with — and here it decides whether work was lawfully allowed to
 *    proceed.
 *  - **Releasing a hold point is a separate act from recording the result.** §17.1: "the whole function of a hold point
 *    is that work may not proceed past it". Passing an inspection and authorising the next operation to start are two
 *    decisions, and on a certified site they are two people — hence `ConstructionInspectionRelease` as its own
 *    permission.
 *  - **A failed inspection cannot release a hold point.** Not a policy but an arithmetic of the thing: the point exists
 *    to stop work until the work is right.
 *  - **A witness point records whether the party attended, and a notice date separate from the request date.** Work may
 *    proceed past a witness point when the invited party does not come — but only if the register can show they were
 *    told, so `notified_on` is what a notice period is measured from.
 *  - **An inspection against a plan row is refused unless that plan is in force.** Requesting against a draft ITP would
 *    let an inspection cite a document nobody has issued, which is the one thing a certification audit reads for.
 */
class InspectionService
{
    /**
     * Request an inspection against an ITP row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function requestAgainst(ItpActivity $activity, array $attributes = []): Inspection
    {
        $itp = Itp::query()->findOrFail($activity->itp_id);

        if (! $itp->isInForce()) {
            throw new InvalidArgumentException(
                "{$itp->displayName()} is {$itp->status}, so an inspection cannot cite it. Issue the plan first — an "
                .'inspection against a draft is an inspection against a document nobody has published, which is the '
                .'first thing a certification audit reads for.'
            );
        }

        if (! $activity->is_active) {
            throw new InvalidArgumentException(
                'That ITP row is no longer active. A superseded point is kept so past inspections still make sense, '
                .'not so new ones can be raised against it.'
            );
        }

        return $this->request(Job::query()->findOrFail($itp->job_id), array_merge([
            'activity_description' => $activity->activity_description,
        ], $attributes, [
            'itp_activity_id' => $activity->getKey(),
            // **Snapshotted.** See the class docblock.
            'point_type' => $activity->point_type,
            'notice_hours' => $activity->notice_hours,
        ]));
    }

    /**
     * Request an inspection with no plan row behind it.
     *
     * §17.1 requires this to be possible: a client's representative walking the site and asking to see a detail is an
     * inspection, and a register that could not record one would push it off the system.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function request(Job $job, array $attributes): Inspection
    {
        if (trim((string) ($attributes['activity_description'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'An inspection needs to say what is being inspected. "Inspection" against a location is a line the '
                .'attending party cannot prepare for.'
            );
        }

        $pointType = $attributes['point_type'] ?? ItpActivity::POINT_REVIEW;

        if (! array_key_exists($pointType, ItpActivity::POINT_TYPES)) {
            throw new InvalidArgumentException(
                'An inspection needs a point type. Hold, witness and review are three different commercial positions, '
                .'and §17.1 exists because collapsing them turns the document into a formality.'
            );
        }

        $requestedOn = Carbon::parse($attributes['requested_on'] ?? now())->toDateString();

        return TenantTransaction::run(fn (): Inspection => Inspection::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'reference' => $attributes['reference'] ?? $this->nextReference($job),
            'point_type' => $pointType,
            'requested_on' => $requestedOn,
        ])));
    }

    /**
     * `INS-1` upward per job.
     *
     * Read off the trailing digits of the highest existing reference, so an imported register continues rather than
     * colliding — and because nothing here is deleted, max-plus-one has no gaps.
     */
    public function nextReference(Job $job): string
    {
        $used = Inspection::query()
            ->where('job_id', $job->getKey())
            ->pluck('reference')
            ->map(fn (string $reference): int => (int) (preg_match('/(\d+)$/', $reference, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return 'INS-'.($used + 1);
    }

    /**
     * Record that the attending party was told, and when.
     *
     * Separate from the request because a notice period runs from when the other party was *told*, not from when
     * somebody raised the paperwork — and at a witness point that date is the whole of the contractor's protection.
     */
    public function notify(Inspection $inspection, ?string $on = null, ?string $scheduledFor = null): Inspection
    {
        if (! $inspection->isOpen()) {
            throw new InvalidArgumentException("{$inspection->displayName()} is already {$inspection->status}.");
        }

        $inspection->update([
            'notified_on' => Carbon::parse($on ?? now())->toDateString(),
            'scheduled_for' => $scheduledFor ? Carbon::parse($scheduledFor)->toDateString() : $inspection->scheduled_for,
            'status' => Inspection::STATUS_SCHEDULED,
        ]);

        return $inspection->refresh();
    }

    /**
     * Record the result.
     *
     * **This never releases a hold point**, whatever the result. Recording that the work is right and authorising the
     * next operation to start are two decisions; see `release()`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(Inspection $inspection, array $attributes): Inspection
    {
        if (! $inspection->isOpen()) {
            throw new InvalidArgumentException(
                "{$inspection->displayName()} was already recorded as {$inspection->status}. Request a re-inspection "
                .'rather than overwriting this one — an inspection that changed its result after the fact is not '
                .'evidence of anything.'
            );
        }

        $status = $attributes['status'] ?? null;

        if (! in_array($status, [
            Inspection::STATUS_PASSED,
            Inspection::STATUS_PASSED_WITH_COMMENTS,
            Inspection::STATUS_FAILED,
            Inspection::STATUS_CANCELLED,
        ], true)) {
            throw new InvalidArgumentException('An inspection needs a result: passed, passed with comments, failed or cancelled.');
        }

        // Never written here. The release is its own act with its own permission.
        unset($attributes['released_hold_point'], $attributes['released_by'], $attributes['released_at']);

        $inspection->update(array_merge($attributes, [
            'inspected_on' => Carbon::parse($attributes['inspected_on'] ?? now())->toDateString(),
            'inspected_by' => $attributes['inspected_by'] ?? auth()->id(),
        ]));

        return $inspection->refresh();
    }

    /**
     * **Release a hold point** — authorise the next operation to start.
     *
     * Three refusals, and each is the point of the mechanism rather than a rule about it:
     *
     *  - **Not a hold point.** There is nothing to release; witness and review points never blocked anything, and a
     *    release recorded against one would make the register's *awaiting release* list meaningless.
     *  - **Not inspected yet.** Releasing before the inspection is exactly the thing a hold point prevents.
     *  - **Failed.** The point exists to stop work until the work is right.
     */
    public function release(Inspection $inspection, ?string $notes = null): Inspection
    {
        if (! $inspection->isHoldPoint()) {
            throw new InvalidArgumentException(
                "{$inspection->displayName()} is a {$inspection->point_type} point, which never blocked work — there "
                .'is nothing to release.'
            );
        }

        if ($inspection->released_hold_point) {
            throw new InvalidArgumentException(
                "{$inspection->displayName()} was released on {$inspection->released_at?->toDateString()}."
            );
        }

        if ($inspection->isOpen()) {
            throw new InvalidArgumentException(
                "{$inspection->displayName()} has not been inspected yet. Releasing a hold point before the inspection "
                .'is precisely what the hold point exists to prevent.'
            );
        }

        if (! $inspection->wasAccepted()) {
            throw new InvalidArgumentException(
                "{$inspection->displayName()} is {$inspection->status}, so the work is not right yet. A hold point is "
                .'released when the work passes, and re-inspected until it does.'
            );
        }

        return TenantTransaction::run(function () use ($inspection, $notes): Inspection {
            $inspection->update([
                'released_hold_point' => true,
                'released_by' => auth()->id(),
                'released_at' => now(),
                'release_notes' => $notes,
            ]);

            return $inspection->refresh();
        });
    }

    /**
     * Add a check-sheet line.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addCheck(Inspection $inspection, array $attributes): InspectionCheck
    {
        if (trim((string) ($attributes['description'] ?? '')) === '') {
            throw new InvalidArgumentException('A check needs describing.');
        }

        return $inspection->checks()->create($attributes);
    }

    /**
     * **Hold points that passed and are waiting on a release** — work standing still, oldest first.
     *
     * The query a site manager needs every morning, and the one the register is built around.
     *
     * @return Collection<int, Inspection>
     */
    public function awaitingRelease(Job $job): Collection
    {
        return Inspection::query()
            ->forJobTree($job)
            ->awaitingRelease()
            ->orderBy('inspected_on')
            ->get();
    }

    /**
     * Hold points that have not been inspected at all — work standing still for a different reason.
     *
     * @return Collection<int, Inspection>
     */
    public function blockingWork(Job $job): Collection
    {
        return Inspection::query()
            ->forJobTree($job)
            ->holdPoints()
            ->open()
            ->orderBy('requested_on')
            ->get();
    }

    /**
     * **Witness points the invited party did not attend** — the contractor's protection, as a list.
     *
     * §17.1: at a witness point work may proceed if the party does not attend. This is the evidence for having
     * proceeded, and it is worth a screen because nobody writes it down at the time.
     *
     * @return Collection<int, Inspection>
     */
    public function witnessedInAbsence(Job $job): Collection
    {
        return Inspection::query()
            ->forJobTree($job)
            ->where('point_type', ItpActivity::POINT_WITNESS)
            ->whereNotNull('notified_on')
            ->whereNotNull('inspected_on')
            ->where('witness_attended', false)
            ->get();
    }

    /**
     * **The proportion of hold points released at the first attempt** — §17.6's leading indicator.
     *
     * Null rather than a percentage where no hold point has been inspected: §17.6's whole complaint is about rates
     * printed from nothing, and 100% first-time on a job with no inspections is the most flattering wrong answer
     * available.
     *
     * First-attempt means the inspection was accepted and released with no earlier failed inspection against the same
     * ITP row. An ad-hoc hold point has no row to compare against, so it counts as its own first attempt.
     */
    public function firstTimeHoldPointRate(Job $job): ?float
    {
        $holdPoints = Inspection::query()
            ->forJobTree($job)
            ->holdPoints()
            ->whereNotNull('inspected_on')
            ->orderBy('inspected_on')
            ->get();

        if ($holdPoints->isEmpty()) {
            return null;
        }

        $failedRows = $holdPoints
            ->filter(fn (Inspection $i): bool => $i->hasFailed() && $i->itp_activity_id !== null)
            ->pluck('itp_activity_id')
            ->unique();

        $firstTime = $holdPoints
            ->filter(fn (Inspection $i): bool => $i->wasAccepted()
                && ($i->itp_activity_id === null || ! $failedRows->contains($i->itp_activity_id)))
            ->count();

        // The denominator is the points somebody actually reached a verdict on, which is what "first time" is a
        // proportion of.
        $decided = $holdPoints->filter(fn (Inspection $i): bool => $i->wasAccepted() || $i->hasFailed())->count();

        return $decided === 0 ? null : round($firstTime / $decided * 100, 1);
    }
}
