<?php

namespace App\Modules\ConstructionField\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Models\Submittal;
use App\Modules\ConstructionField\Models\SubmittalReview;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The submittal register — `docs/construction-management-plan.md` §16.3.
 *
 * **The submit-by date is never written here, and that is the section's whole point.** It is computed from the
 * required-on-site date and the four durations, every time anybody asks. §16.3: "typed, it goes stale the day the
 * programme moves, and a stale submit-by date is worse than none."
 *
 * Four rules live in this service.
 *
 *  - **Every submission is a round**, and the round is the record. `submit()` opens round one; a `revise_and_resubmit`
 *    return followed by `submit()` opens round two. The status column is a projection of the latest round, maintained
 *    here and never typed, because a form that let somebody set it would let the register disagree with its own
 *    history.
 *  - **The review period is snapshotted onto the round.** A round whose overrun was computed against fourteen days
 *    keeps saying fourteen, whatever the contract is later renegotiated to.
 *  - **A reviewer's overrun is claimable and the contractor's own lateness is not.** The contractor submits, so being
 *    late to submit is a risk it owns; a reviewer past their period has taken somebody else's programme. Only the
 *    former has a notice behind it, and `raiseDelayForOverrun()` dates it the day the period expired.
 *  - **Approved-as-noted clears the item.** It means "build it, with these corrections", the fabricator starts, and a
 *    register that treated it as unapproved would show a job blocked on four hundred items that are all proceeding.
 */
class SubmittalService
{
    /**
     * Register a submittal that will be needed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(Job $job, array $attributes): Submittal
    {
        foreach (['spec_section' => 'a specification section', 'title' => 'a title'] as $field => $what) {
            if (trim((string) ($attributes[$field] ?? '')) === '') {
                throw new InvalidArgumentException(
                    "A submittal needs {$what}. The register is organised by section and read by title, and a row "
                    .'missing either is a row nobody finds when the item is needed.'
                );
            }
        }

        if (! array_key_exists($attributes['type'] ?? '', Submittal::TYPES)) {
            throw new InvalidArgumentException(
                'A submittal needs a type. A sample and a shop drawing carry different lead times and different '
                .'review periods, and the submit-by date is computed from both.'
            );
        }

        return TenantTransaction::run(fn (): Submittal => Submittal::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
        ])));
    }

    /**
     * Edit it, refused once it is cleared.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Submittal $submittal, array $attributes): Submittal
    {
        if ($submittal->isCleared()) {
            throw new InvalidArgumentException(
                "{$submittal->displayName()} is {$submittal->status}. Its lead times are the record of what the "
                .'approval bought — register a new submittal if the item has changed.'
            );
        }

        // The status is a projection of the rounds, not a field. Silently dropped rather than refused, because a form
        // posting its whole state is the ordinary case and a refusal there would be an error nobody caused.
        unset($attributes['status']);

        $submittal->update($attributes);

        return $submittal->refresh();
    }

    /**
     * Submit it, which opens a round.
     *
     * @param  array<string, mixed>  $attributes  the reviewer, the revision, and the day it went
     */
    public function submit(Submittal $submittal, array $attributes = []): SubmittalReview
    {
        if ($submittal->isCleared()) {
            throw new InvalidArgumentException("{$submittal->displayName()} is already {$submittal->status}.");
        }

        $open = $submittal->reviews()->whereNull('returned_on')->first();

        if ($open !== null) {
            throw new InvalidArgumentException(
                "{$submittal->displayName()} is out for review as round {$open->round}, sent on "
                ."{$open->sent_on->toDateString()}. Record that round's return before submitting again — two rounds "
                .'open at once is how a register loses count of how many it has been through.'
            );
        }

        $sentOn = Carbon::parse($attributes['sent_on'] ?? now())->toDateString();

        return TenantTransaction::run(function () use ($submittal, $attributes, $sentOn): SubmittalReview {
            $round = (int) $submittal->reviews()->max('round') + 1;

            $review = $submittal->reviews()->create(array_merge($attributes, [
                'round' => $round,
                'sent_on' => $sentOn,
                // Snapshotted, not read later. See the class docblock.
                'review_period_days' => $attributes['review_period_days'] ?? $submittal->review_period_days,
                'revision' => $attributes['revision'] ?? $submittal->revision,
            ]));

            $submittal->update([
                'status' => Submittal::STATUS_UNDER_REVIEW,
                // The *first* submission, kept: it is what the register's lateness is measured to, and a resubmission
                // overwriting it would retire the original lateness as though it had never happened.
                'submitted_on' => $submittal->submitted_on ?? $sentOn,
                'revision' => $review->revision,
            ]);

            return $review->refresh();
        });
    }

    /**
     * Record a round's return.
     *
     * The result decides the header: cleared for the two approvals, back round for the two that send it back. The
     * status is written from here rather than chosen, which is what keeps it agreeing with the rounds.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordReturn(SubmittalReview $review, array $attributes): SubmittalReview
    {
        if ($review->isReturned()) {
            throw new InvalidArgumentException(
                "Round {$review->round} came back on {$review->returned_on->toDateString()}. Submit again to open a "
                .'new round rather than overwriting this one — the rounds are the schedule record.'
            );
        }

        if (! array_key_exists($attributes['result'] ?? '', SubmittalReview::RESULTS)) {
            throw new InvalidArgumentException(
                'A returned review needs a result. "Came back" without one leaves the register unable to say whether '
                .'the item may be built.'
            );
        }

        $returnedOn = Carbon::parse($attributes['returned_on'] ?? now())->toDateString();

        if (Carbon::parse($returnedOn)->lt($review->sent_on)) {
            throw new InvalidArgumentException('A review cannot come back before it went out.');
        }

        return TenantTransaction::run(function () use ($review, $attributes, $returnedOn): SubmittalReview {
            $review->update(array_merge($attributes, ['returned_on' => $returnedOn]));

            $submittal = Submittal::query()->findOrFail($review->submittal_id);

            $status = match ($attributes['result']) {
                SubmittalReview::RESULT_APPROVED => Submittal::STATUS_APPROVED,
                SubmittalReview::RESULT_APPROVED_AS_NOTED => Submittal::STATUS_APPROVED_AS_NOTED,
                SubmittalReview::RESULT_REJECTED => Submittal::STATUS_REJECTED,
                SubmittalReview::RESULT_FOR_RECORD => Submittal::STATUS_CLOSED,
                default => Submittal::STATUS_REVISE_AND_RESUBMIT,
            };

            $submittal->update([
                'status' => $status,
                'approved_on' => in_array($status, Submittal::CLEARED, true)
                    ? ($submittal->approved_on ?? $returnedOn)
                    : null,
            ]);

            return $review->refresh();
        });
    }

    /**
     * **Raise §13's delay event for a reviewer's overrun**, dated the day their period expired.
     *
     * Not the day it came back, and not today: the notice period runs from the event, and the event is the reviewer
     * passing their own deadline. This is the only part of a submittal's lateness with a claim in it — see the class
     * docblock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raiseDelayForOverrun(SubmittalReview $review, array $attributes = []): DelayEvent
    {
        if ($review->delay_event_id !== null) {
            throw new InvalidArgumentException(
                'This round already has a delay event against it. Two notices for one cause is two claims the other '
                .'side can play against each other.'
            );
        }

        if (! $review->hasOverrun()) {
            throw new InvalidArgumentException(
                "Round {$review->round} is inside its {$review->review_period_days}-day review period, so there is "
                .'nothing to notify. A notice for a period nobody has exceeded is what turns a notice register into '
                .'noise.'
            );
        }

        $submittal = Submittal::query()->findOrFail($review->submittal_id);
        $job = Job::query()->findOrFail($submittal->job_id);

        return TenantTransaction::run(function () use ($review, $submittal, $job, $attributes): DelayEvent {
            $event = app(DelayEventService::class)->raise($job, array_merge([
                'title' => "Late review: {$submittal->displayName()}",
                'cause_category' => 'late_information',
                'description' => "Round {$review->round} was sent on {$review->sent_on->toDateString()} against a "
                    ."{$review->review_period_days}-day review period and took {$review->turnaroundDays()} days.",
                'contract_id' => $submittal->contract_id,
                'claimed_days' => $review->overrunDays(),
            ], $attributes, [
                'occurred_on' => $review->overrunStartedOn(),
            ]));

            $review->update(['delay_event_id' => $event->getKey()]);

            return $event;
        });
    }

    /**
     * **What has to be submitted next**, soonest first — the register's primary report.
     *
     * Ordered on the computed date rather than in SQL, because that date is not a column: it is the required-on-site
     * date less four durations, and the whole reason §16.3 refuses to store it is that a stored one goes stale. Items
     * with no programme date sort last, since they cannot be sequenced against the ones that have one.
     *
     * @return Collection<int, Submittal>
     */
    public function dueToSubmit(Job $job): Collection
    {
        return Submittal::query()
            ->forJobTree($job)
            ->awaitingSubmission()
            ->get()
            ->sortBy(fn (Submittal $s): string => $s->submitBy()?->toDateString() ?? '9999-12-31')
            ->values();
    }

    /**
     * **Already late to submit** — the finding a real job needs on day one.
     *
     * A job where nobody did the subtraction when the programme was agreed has a great many of these before anybody has
     * done anything wrong, which is precisely why the date is computed rather than typed.
     *
     * @return Collection<int, Submittal>
     */
    public function lateToSubmit(Job $job, ?string $asAt = null): Collection
    {
        return $this->dueToSubmit($job)
            ->filter(fn (Submittal $s): bool => $s->isLateToSubmit($asAt))
            ->values();
    }

    /**
     * The ones that will stop the job: long lead, not yet cleared.
     *
     * @return Collection<int, Submittal>
     */
    public function longLeadOutstanding(Job $job): Collection
    {
        return Submittal::query()->forJobTree($job)->longLead()->outstanding()->get();
    }

    /**
     * Submittals that have been round more than once — §16.3's schedule risk.
     *
     * @return Collection<int, Submittal>
     */
    public function resubmitted(Job $job): Collection
    {
        // `has('reviews', '>', 1)` rather than `withCount()->having()`: a HAVING clause against a subquery alias is
        // not an aggregate, and SQLite refuses it outright — which this suite tests on and MySQL would have let
        // through. The correlated-subquery form works on both.
        return Submittal::query()
            ->forJobTree($job)
            ->has('reviews', '>', 1)
            ->withCount('reviews')
            ->get();
    }

    /**
     * Rounds the reviewer kept beyond their period, with no notice behind them.
     *
     * Filtered in PHP: the arithmetic is a date difference against a per-row period, and expressing it in SQL means a
     * dialect-specific date function — this suite tests on SQLite and ships on MySQL, so a query that worked would have
     * been a query that worked in tests.
     *
     * @return Collection<int, SubmittalReview>
     */
    public function unnotifiedReviewOverruns(Job $job, ?string $asAt = null): Collection
    {
        return SubmittalReview::query()
            ->with('submittal')
            ->sent()
            ->whereNull('delay_event_id')
            ->whereIn('submittal_id', Submittal::query()->forJobTree($job)->select('id'))
            ->get()
            ->filter(fn (SubmittalReview $review): bool => $review->overrunUnnotified($asAt))
            ->values();
    }

    /**
     * The average days the reviewer has taken, over returned rounds.
     *
     * Null rather than zero where nothing has come back: a turnaround of zero on a job with fourteen submittals out is
     * the flattering figure this register exists to prevent.
     */
    public function averageTurnaroundDays(Job $job): ?float
    {
        $returned = SubmittalReview::query()
            ->returned()
            ->whereIn('submittal_id', Submittal::query()->forJobTree($job)->select('id'))
            ->get();

        if ($returned->isEmpty()) {
            return null;
        }

        return round((float) $returned->avg(fn (SubmittalReview $r): int => $r->turnaroundDays()), 1);
    }
}
