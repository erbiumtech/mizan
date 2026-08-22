<?php

namespace App\Modules\ConstructionField\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Delay events and their notice clock — `docs/construction-management-plan.md` §13.
 *
 * **The clock is why this exists, and §13 is unusually direct about it:** *"A due-date with a notification attached is
 * worth more commercially than the entire programme: a valid claim lost to a missed notice is the single most common way
 * a contractor donates money, and it fails in absolute silence."* That last clause is what makes it the first thing
 * Phase 9 builds. Every other failure in this suite leaves a wrong figure somewhere a report can find; this one leaves
 * nothing at all — the event happened, nobody wrote inside the window, and the entitlement is gone with no number to be
 * wrong.
 *
 * Four rules live here rather than in a form:
 *
 *  - **The due date is computed once and stored.** `occurred_on + notice days`, where the days come from the contract
 *    when there is one and from config when there is not. Storing it means a later edit to the contract's period cannot
 *    move a deadline somebody has already been warned about; `notice_days` is stored beside it so the date can be
 *    explained rather than merely trusted.
 *  - **Notice can be served late, and lateness is recorded rather than refused.** Most contracts make the bar
 *    conditional on prejudice, waiver or the certifier's discretion, so refusing the entry would delete the only
 *    evidence of what actually happened. The row says the notice was late and the argument stays with the people having
 *    it.
 *  - **Determining is separate from raising**, and it is the one permission of its own: awarding days moves the
 *    completion date and decides whether liquidated damages can be levied at all.
 *  - **Awarding more than was claimed is refused.** A determination is an answer to a claim; granting sixty days
 *    against a claim for thirty is not a generous assessment, it is a different event nobody has notified.
 */
class DelayEventService
{
    /**
     * Raise an event, which is what starts the clock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raise(Job $job, array $attributes): DelayEvent
    {
        $occurredOn = Carbon::parse($attributes['occurred_on'] ?? now())->startOfDay();

        if (trim((string) ($attributes['title'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A delay event needs a title. "Delay" on its own is a row nobody can assess in six months.'
            );
        }

        if (! array_key_exists($attributes['cause_category'] ?? '', DelayEvent::CAUSES)) {
            throw new InvalidArgumentException(
                'A delay event needs a cause category: who bears the risk is the first thing an assessment turns on.'
            );
        }

        $noticeDays = $this->noticeDaysFor($attributes['contract_id'] ?? null);

        return TenantTransaction::run(fn (): DelayEvent => DelayEvent::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'reference' => $attributes['reference'] ?? $this->nextReference($job),
            'occurred_on' => $occurredOn->toDateString(),
            // **The clock**, computed once and stored — see the class docblock.
            'notice_days' => $noticeDays,
            'notice_required_by' => $occurredOn->copy()->addDays($noticeDays)->toDateString(),
        ])));
    }

    /**
     * The notice period that applies: the contract's own, or the shipped default.
     *
     * Read with a raw query rather than through the `Contract` model, and that is the licensing claim made structural:
     * `construction_field` requires only `construction` (§18), so this module must work with the contracts table absent
     * from a company's licence — and naming the class would put `construction_contracts` in its import graph. The
     * column is one integer; a model would buy nothing and cost the boundary.
     */
    public function noticeDaysFor(int|string|null $contractId): int
    {
        $default = (int) config('construction.delay.notice_days');

        if ($contractId === null || ! modules()->enabled('construction_contracts')) {
            return $default;
        }

        $days = DB::table('construction_contracts')
            ->where('id', $contractId)
            ->value('delay_notice_days');

        return $days === null ? $default : (int) $days;
    }

    /** `DE-1` upward per job, read off the trailing digits so a withdrawn event does not have its number reused. */
    public function nextReference(Job $job): string
    {
        $used = DelayEvent::query()
            ->where('job_id', $job->getKey())
            ->pluck('reference')
            ->map(fn (string $reference): int => (int) (preg_match('/(\d+)$/', $reference, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return 'DE-'.($used + 1);
    }

    /**
     * Record that notice was served, which stops the clock.
     *
     * The date is the day the notice went to the Engineer, not the day somebody typed it — notice served on the 3rd and
     * recorded on the 11th is notice served on the 3rd, and the difference is often the whole argument.
     *
     * **A late notice is accepted and marked late.** Refusing it would delete the only record of what happened, and
     * whether lateness bars the claim is a contractual question this service does not answer.
     */
    public function giveNotice(DelayEvent $event, ?string $on = null, ?int $documentId = null): DelayEvent
    {
        if ($event->noticeGiven()) {
            throw new InvalidArgumentException(
                "Notice of {$event->reference} was already served on {$event->notice_given_on->toDateString()}. "
                .'Notice cannot be served twice — a second one would move the date the contract reads.'
            );
        }

        if ($event->isClosed()) {
            throw new InvalidArgumentException("{$event->reference} is {$event->status} and needs no notice.");
        }

        $given = Carbon::parse($on ?? now())->startOfDay();

        if ($given->lt($event->occurred_on->copy()->startOfDay())) {
            throw new InvalidArgumentException(
                'Notice cannot be served before the event happened. Correct the date the event occurred, or the date '
                .'on the notice.'
            );
        }

        return TenantTransaction::run(function () use ($event, $given, $documentId): DelayEvent {
            $event->update([
                'notice_given_on' => $given->toDateString(),
                'notice_document_id' => $documentId ?? $event->notice_document_id,
                'status' => DelayEvent::STATUS_NOTIFIED,
                // The particulars clock starts from the notice: that is the date the contractor controls and can plan
                // against, unlike the date of the event itself.
                'particulars_due_by' => $event->particulars_due_by
                    ?? $given->copy()->addDays((int) config('construction.delay.particulars_days'))->toDateString(),
            ]);

            return $event->refresh();
        });
    }

    /** The detailed submission that follows the notice, with what is being claimed. */
    public function submitParticulars(
        DelayEvent $event,
        ?float $claimedDays = null,
        ?float $costClaimed = null,
        ?string $on = null,
    ): DelayEvent {
        if (! $event->noticeGiven()) {
            throw new InvalidArgumentException(
                "Notice of {$event->reference} has not been served. Particulars before a notice is a submission the "
                .'contract has no slot for — serve the notice first, even if the detail follows later.'
            );
        }

        if ($event->isClosed()) {
            throw new InvalidArgumentException("{$event->reference} is {$event->status}.");
        }

        $event->update([
            'particulars_submitted_on' => Carbon::parse($on ?? now())->toDateString(),
            'claimed_days' => $claimedDays ?? $event->claimed_days,
            'cost_claimed' => $costClaimed ?? $event->cost_claimed,
            'status' => DelayEvent::STATUS_PARTICULARS_SUBMITTED,
        ]);

        return $event->refresh();
    }

    /**
     * Determine the event: award days, money, or neither, with a reason.
     *
     * **Awarding more than was claimed is refused**, because a determination answers a claim — sixty days against a
     * claim for thirty is a different event that nobody has notified. Awarding *less*, or nothing, is ordinary and is
     * exactly what the reason field is for.
     */
    public function determine(
        DelayEvent $event,
        ?float $awardedDays,
        ?float $costAwarded,
        string $reason,
        ?string $on = null,
    ): DelayEvent {
        if ($event->isClosed()) {
            throw new InvalidArgumentException(
                "{$event->reference} is already {$event->status}. A determination is made once — reopening one is a "
                .'new event or a formal review, and both leave this row as the record of what was decided.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A determination needs a reason. The other party will read it, and "0 days" with no grounds is the '
                .'sentence that goes to adjudication.'
            );
        }

        if ($awardedDays !== null && $event->claimed_days !== null && $awardedDays > (float) $event->claimed_days) {
            throw new InvalidArgumentException(
                'Awarding '.$awardedDays.' days against a claim for '.$event->claimed_days.' is more than was asked '
                .'for. That is a different event, and it needs its own notice.'
            );
        }

        return TenantTransaction::run(function () use ($event, $awardedDays, $costAwarded, $reason, $on): DelayEvent {
            $event->update([
                'awarded_days' => $awardedDays,
                'cost_awarded' => $costAwarded,
                'determination_reason' => $reason,
                'determined_on' => Carbon::parse($on ?? now())->toDateString(),
                'determined_by' => auth()->id(),
                // Nothing awarded at all is a rejection, and saying so on the row is what makes the register readable:
                // a "determined" event with zero days reads as an oversight to whoever finds it next year.
                'status' => ($awardedDays === null || $awardedDays <= 0.0) && ($costAwarded === null || $costAwarded <= 0.0)
                    ? DelayEvent::STATUS_REJECTED
                    : DelayEvent::STATUS_DETERMINED,
            ]);

            return $event->refresh();
        });
    }

    /** Withdraw an event, with a reason — the row stays, because the reference series has no gaps. */
    public function withdraw(DelayEvent $event, string $reason): DelayEvent
    {
        if ($event->isClosed()) {
            throw new InvalidArgumentException("{$event->reference} is already {$event->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Withdrawing an event needs a reason.');
        }

        $event->update([
            'status' => DelayEvent::STATUS_WITHDRAWN,
            'determination_reason' => $reason,
        ]);

        return $event->refresh();
    }

    /**
     * **Events whose notice has newly crossed a warning threshold** — what the nightly run sends.
     *
     * Newly, which is what `notice_notified_at_days` is for: without it the same event is reported every night until
     * somebody acts, and the backlog buries the one that appeared today. Copied from §12's compliance register, whose
     * own migration explains the column.
     *
     * @return Collection<int, array{event: DelayEvent, days: int, threshold: int}>
     */
    public function dueForWarning(?string $asAt = null): Collection
    {
        $asAt = Carbon::parse($asAt ?? now())->toDateString();

        return DelayEvent::query()
            ->with('job')
            ->awaitingNotice()
            ->get()
            ->map(function (DelayEvent $event) use ($asAt): ?array {
                $threshold = $event->warningThreshold($asAt);

                if ($threshold === null) {
                    return null;
                }

                // Already warned at this threshold or a tighter one. Lower is tighter, so only a *smaller* threshold
                // than the last one sent is news.
                if ($event->notice_notified_at_days !== null && $event->notice_notified_at_days <= $threshold) {
                    return null;
                }

                return [
                    'event' => $event,
                    'days' => (int) $event->daysUntilNoticeDue($asAt),
                    'threshold' => $threshold,
                ];
            })
            ->filter()
            ->values();
    }

    public function markWarned(DelayEvent $event, int $threshold): void
    {
        $event->update(['notice_notified_at_days' => $threshold]);
    }

    /**
     * Events already past their notice date with nothing served — the exposure list.
     *
     * A query rather than a habit, which is the shape §12's un-notified back-charge list settled: the figure a company
     * has lost and does not yet know it has lost should be a screen somebody can open.
     *
     * @return Collection<int, DelayEvent>
     */
    public function timeBarred(?string $asAt = null): Collection
    {
        return DelayEvent::query()
            ->with('job')
            ->awaitingNotice()
            ->get()
            ->filter(fn (DelayEvent $event): bool => $event->isTimeBarred($asAt))
            ->values();
    }
}
