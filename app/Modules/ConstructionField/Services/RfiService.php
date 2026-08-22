<?php

namespace App\Modules\ConstructionField\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Models\Rfi;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The RFI register — `docs/construction-management-plan.md` §16.2.
 *
 * Four rules live here rather than in a form.
 *
 *  - **The numbering has no gaps, so nothing is deleted.** §16.2 asks for "`rfi_number` (per job, no gaps)", and that is
 *    a rule about removal rather than about counting: the register is read sequentially and quoted in correspondence, so
 *    "where is RFI 14?" must never be answerable with "somebody deleted it". `cancel()` is the way out and the number
 *    stays used.
 *  - **Closing requires an answer.** An RFI closed with nothing against it is a question abandoned, and it disappears
 *    from the one report that matters — what is still outstanding. Something that no longer needs answering is
 *    cancelled with a reason, which is a different fact and reads as one.
 *  - **A late answer is recorded as late, not refused.** The same shape as §13's late notice: the response time belongs
 *    to the other side, and refusing the entry would delete the only record of what happened.
 *  - **The answer is dated the day it was given**, not the day somebody transcribed it. An answer given on the 3rd and
 *    typed on the 11th took the days it took, and dating it from the transcription flatters the party being measured.
 *
 * And the thing this register is worth having for: **an RFI with a stated time impact and no delay event behind it.**
 * Late information is already a cause on §13's event, an RFI is where such a delay is first written down, and the notice
 * period is running from the day the answer was needed. `raiseDelay()` starts that clock at the right date.
 */
class RfiService
{
    /**
     * Raise one, which starts its clock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raise(Job $job, array $attributes): Rfi
    {
        if (trim((string) ($attributes['subject'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'An RFI needs a subject. It is what appears in the register, in the chasing email and in the '
                .'correspondence that quotes it.'
            );
        }

        if (trim((string) ($attributes['question'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'An RFI needs a question. A subject line on its own is something the other side can answer with '
                .'"please clarify", which costs another two weeks.'
            );
        }

        if (! array_key_exists($attributes['ball_in_court'] ?? '', Rfi::COURTS)) {
            throw new InvalidArgumentException(
                'An RFI needs somebody\'s court to sit in. "Seventeen RFIs with the Architect" is the report this '
                .'register exists to produce, and it cannot be written from a blank column.'
            );
        }

        return TenantTransaction::run(fn (): Rfi => Rfi::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'rfi_number' => $attributes['rfi_number'] ?? $this->nextNumber($job),
            'raised_on' => Carbon::parse($attributes['raised_on'] ?? now())->toDateString(),
        ])));
    }

    /**
     * `RFI-1` upward per job.
     *
     * Read off the trailing digits of the highest existing number rather than counted, so an imported register that
     * starts at 100 continues from 101 — and because a count would collide the moment anything was ever removed. Since
     * nothing is ever removed, max-plus-one is gapless.
     */
    public function nextNumber(Job $job): string
    {
        $used = Rfi::query()
            ->where('job_id', $job->getKey())
            ->pluck('rfi_number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return 'RFI-'.($used + 1);
    }

    /**
     * Edit an open RFI.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Rfi $rfi, array $attributes): Rfi
    {
        if ($rfi->isClosed()) {
            throw new InvalidArgumentException(
                "{$rfi->displayName()} is {$rfi->status} and cannot be changed. Re-raise the question if it is still "
                .'live — the second one has its own clock, which is what the register should show.'
            );
        }

        $rfi->update($attributes);

        return $rfi->refresh();
    }

    /** Move the ball, as a role and — where there is a contact record — as a person. */
    public function assign(Rfi $rfi, string $court, int|string|null $contactId = null): Rfi
    {
        if (! array_key_exists($court, Rfi::COURTS)) {
            throw new InvalidArgumentException("{$court} is not a court an RFI can sit in.");
        }

        return $this->update($rfi, [
            'ball_in_court' => $court,
            'ball_in_court_contact_id' => $contactId,
        ]);
    }

    /**
     * Record the answer.
     *
     * **Dated the day it was given.** And a late answer is accepted and marked — whether lateness founds a claim is a
     * contractual question this service does not answer, and refusing the entry would delete the evidence either way.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function answer(Rfi $rfi, array $attributes): Rfi
    {
        if ($rfi->isClosed()) {
            throw new InvalidArgumentException("{$rfi->displayName()} is {$rfi->status} and takes no answer.");
        }

        if (trim((string) ($attributes['answer'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'An answer needs recording in words. A date with nothing against it is a closed RFI nobody can act on.'
            );
        }

        return TenantTransaction::run(function () use ($rfi, $attributes): Rfi {
            $rfi->update(array_merge($attributes, [
                'answered_on' => Carbon::parse($attributes['answered_on'] ?? now())->toDateString(),
                'answer_recorded_by' => auth()->id(),
                'status' => Rfi::STATUS_ANSWERED,
            ]));

            return $rfi->refresh();
        });
    }

    /**
     * Close it, which requires an answer.
     *
     * An RFI closed with nothing against it vanishes from the outstanding report while the question is still unanswered
     * — the register's one job, undone. Use `cancel()` for a question that no longer needs answering.
     */
    public function close(Rfi $rfi, ?string $on = null): Rfi
    {
        if ($rfi->isClosed()) {
            throw new InvalidArgumentException("{$rfi->displayName()} is already {$rfi->status}.");
        }

        if (! $rfi->isAnswered()) {
            throw new InvalidArgumentException(
                "{$rfi->displayName()} has no answer against it, so closing it would take an unanswered question off "
                .'the outstanding list. Record the answer, or cancel it with a reason.'
            );
        }

        $rfi->update([
            'status' => Rfi::STATUS_CLOSED,
            'closed_on' => Carbon::parse($on ?? now())->toDateString(),
            'closed_by' => auth()->id(),
        ]);

        return $rfi->refresh();
    }

    /**
     * Cancel it, with a reason, keeping the number.
     *
     * The design gone wrong in the alternative is worth naming: a deleted RFI leaves a hole in a register somebody
     * quotes by number, and the hole is indistinguishable from a cover-up. A cancelled one says what happened.
     */
    public function cancel(Rfi $rfi, string $reason): Rfi
    {
        if ($rfi->isClosed()) {
            throw new InvalidArgumentException("{$rfi->displayName()} is already {$rfi->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Cancelling an RFI needs a reason. The number stays in the register either way, and a cancelled row '
                .'with no explanation is the gap this avoids, in a different shape.'
            );
        }

        $rfi->update([
            'status' => Rfi::STATUS_CANCELLED,
            'cancel_reason' => $reason,
            'closed_on' => now()->toDateString(),
            'closed_by' => auth()->id(),
        ]);

        return $rfi->refresh();
    }

    /**
     * **Raise §13's delay event for this RFI's time impact, and start the clock on the right day.**
     *
     * The day the answer was needed and did not come — not today, and not the day the question was asked. Dating it now
     * is how a claim is time-barred by its own paperwork; dating it from the raise date claims delay for a period the
     * work was not yet blocked in.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raiseDelay(Rfi $rfi, array $attributes = []): DelayEvent
    {
        if ($rfi->delay_event_id !== null) {
            throw new InvalidArgumentException(
                "{$rfi->displayName()} already has a delay event against it. Two notices for one cause is two claims "
                .'the other side can play against each other.'
            );
        }

        if ($rfi->time_impact_flag !== Rfi::IMPACT_YES) {
            throw new InvalidArgumentException(
                "{$rfi->displayName()} does not state a time impact. Mark the impact first — a notice for a delay "
                .'nobody has assessed is what turns a notice register into noise.'
            );
        }

        return TenantTransaction::run(function () use ($rfi, $attributes): DelayEvent {
            // Fetched by key rather than read off the relation: a lazy load here would throw on any RFI that was not
            // created in this request, which is every one a screen reaches.
            $job = Job::query()->findOrFail($rfi->job_id);

            $event = app(DelayEventService::class)->raise($job, array_merge([
                'title' => "Late information: {$rfi->subject}",
                'cause_category' => 'late_information',
                'description' => "Raised from {$rfi->rfi_number}. {$rfi->question}",
                'contract_id' => $rfi->contract_id,
                'claimed_days' => $rfi->time_impact_days,
            ], $attributes, [
                'occurred_on' => $rfi->delayStartedOn(),
            ]));

            $rfi->update(['delay_event_id' => $event->getKey()]);

            return $event;
        });
    }

    /** Record the variation this question became — §16.2's last column, and the origin a change would otherwise lose. */
    public function recordVariation(Rfi $rfi, int|string $variationId): Rfi
    {
        $rfi->update(['variation_id' => $variationId]);

        return $rfi->refresh();
    }

    /**
     * What is outstanding, oldest first — the register's primary report.
     *
     * @return Collection<int, Rfi>
     */
    public function outstanding(Job $job): Collection
    {
        return Rfi::query()
            ->forJobTree($job)
            ->awaitingAnswer()
            ->orderBy('required_by')
            ->orderBy('raised_on')
            ->get();
    }

    /**
     * Overdue as at a date.
     *
     * @return Collection<int, Rfi>
     */
    public function overdue(Job $job, ?string $asAt = null): Collection
    {
        return Rfi::query()->forJobTree($job)->overdue($asAt)->orderBy('required_by')->get();
    }

    /**
     * **"Seventeen RFIs sitting with the Architect"** — §16.2's named report, as a count per role.
     *
     * Keyed by role rather than by contact for the reason the two columns exist: the individual changes over a two-year
     * job and the role does not, so a count per person fragments the same answer across three names.
     *
     * @return array<string, int>
     */
    public function byCourt(Job $job): array
    {
        return Rfi::query()
            ->forJobTree($job)
            ->awaitingAnswer()
            ->get()
            ->groupBy('ball_in_court')
            ->map(fn (Collection $rfis): int => $rfis->count())
            ->all();
    }

    /**
     * **Stated time impacts with nobody notified** — the exposure this register earns its place with.
     *
     * @return Collection<int, Rfi>
     */
    public function unnotifiedTimeImpacts(Job $job): Collection
    {
        return Rfi::query()->forJobTree($job)->unnotifiedTimeImpact()->get();
    }

    /**
     * Stated impacts still carrying no figure.
     *
     * §16.2 wants flags at raise time, and does not want them to stay flags for ever.
     *
     * @return Collection<int, Rfi>
     */
    public function unquantifiedImpacts(Job $job): Collection
    {
        return Rfi::query()
            ->forJobTree($job)
            ->live()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('cost_impact_flag', Rfi::IMPACT_YES)->whereNull('cost_impact_estimate'))
                ->orWhere(fn ($q) => $q->where('time_impact_flag', Rfi::IMPACT_YES)->whereNull('time_impact_days')))
            ->get();
    }

    /**
     * The average days the other side took, over answered RFIs only.
     *
     * Null rather than zero where nothing has been answered: a response time of zero on a job with fourteen
     * outstanding questions is the flattering figure §16.2's whole design is written against.
     */
    public function averageResponseDays(Job $job): ?float
    {
        $answered = Rfi::query()
            ->forJobTree($job)
            ->whereNotNull('answered_on')
            ->get();

        if ($answered->isEmpty()) {
            return null;
        }

        return round((float) $answered->avg(fn (Rfi $rfi): int => (int) $rfi->responseDays()), 1);
    }
}
