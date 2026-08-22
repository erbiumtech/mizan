<?php

namespace App\Modules\ConstructionQhse\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Models\Ncr;
use App\Modules\ConstructionQhse\Models\QhseAction;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The one actions register — `docs/construction-management-plan.md` §17.4.
 *
 * **The screen this exists to make possible is "everything overdue, from every source, in one list".** §17.4: "four
 * separate action tables produce four *overdue actions* reports that never agree", and the safety manager's one
 * genuinely useful screen becomes a four-way union nobody maintains.
 *
 * Four rules live here.
 *
 *  - **An action needs somebody's name against it**, in any of the three forms. An action nobody is assigned to is an
 *    action nobody does, and the free-text label is the one that always works — on most sites most of the people who
 *    have to do something are a subcontractor's, in no table here.
 *  - **Done and verified are two acts.** "Done" is the assignee's claim; "verified" is somebody else's confirmation, and
 *    `verify()` refuses an action nobody has claimed to have done. Conflating them would let whoever caused a finding
 *    close it.
 *  - **Cancelling needs a reason**, and it is a status rather than a deletion: "we decided this was not needed on the
 *    14th" is the answer to a question somebody asks again in month nine.
 *  - **NCR CAPA dates are *not* mirrored into this table.** §17.2 puts corrective and preventive action on the NCR
 *    because ISO 9001 asks for them there; this table is the working list. A mirror would be two sources for one date —
 *    the trap this whole plan refuses everywhere else — so `everythingOverdue()` assembles both and **labels which
 *    source each row came from**, exactly as §17.6 requires of an exposure denominator.
 */
class ActionService
{
    /**
     * Raise an action against any QHSE object.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raise(Model $subject, array $attributes): QhseAction
    {
        if (trim((string) ($attributes['description'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'An action needs describing. "Action required" against a finding is a row the assignee cannot act on '
                .'and the verifier cannot check.'
            );
        }

        if (! array_key_exists($attributes['action_type'] ?? 'corrective', QhseAction::TYPES)) {
            throw new InvalidArgumentException(
                'An action needs a type. Containment and correction are different things — cordoning a hole off is not '
                .'the same as filling it, and a register that could not distinguish them would report a site as having '
                .'addressed something when all it did was put a barrier round it.'
            );
        }

        if (blank($attributes['assignee_label'] ?? null)
            && blank($attributes['assigned_contact_id'] ?? null)
            && blank($attributes['assigned_user_id'] ?? null)) {
            throw new InvalidArgumentException(
                'An action needs somebody against it. A user, a contact, or just a name typed in — an action nobody is '
                .'assigned to is an action nobody does.'
            );
        }

        $jobId = $attributes['job_id'] ?? $subject->job_id ?? null;

        if ($jobId === null) {
            throw new InvalidArgumentException(
                'An action needs a job. Every report on this register is per job, which is why the column is here '
                .'rather than reached through five different parent types.'
            );
        }

        return TenantTransaction::run(fn (): QhseAction => QhseAction::create(array_merge($attributes, [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'job_id' => $jobId,
        ])));
    }

    /**
     * Edit it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(QhseAction $action, array $attributes): QhseAction
    {
        if (! $action->isLive()) {
            throw new InvalidArgumentException(
                "This action is {$action->status}. Raise a new one if there is more to do — an action that changed "
                .'after it was verified is not a record of anything.'
            );
        }

        // Each has its own act.
        unset(
            $attributes['status'], $attributes['completed_on'], $attributes['completed_by'],
            $attributes['verified_on'], $attributes['verified_by'],
        );

        $action->update($attributes);

        return $action->refresh();
    }

    /** Somebody has started on it, which is worth distinguishing from nobody having looked. */
    public function start(QhseAction $action): QhseAction
    {
        if (! $action->isLive()) {
            throw new InvalidArgumentException("This action is {$action->status}.");
        }

        $action->update(['status' => QhseAction::STATUS_IN_PROGRESS]);

        return $action->refresh();
    }

    /**
     * **The assignee's claim that it is done.** Not a closure.
     *
     * §17.4 asks for completion *and* verification, and this writes only the first — the action stays live and shows as
     * awaiting verification, which is the state a register with only "closed" would lose.
     */
    public function complete(QhseAction $action, ?string $on = null, ?string $notes = null): QhseAction
    {
        if (! $action->isLive()) {
            throw new InvalidArgumentException("This action is {$action->status}.");
        }

        $action->update([
            'status' => QhseAction::STATUS_DONE,
            'completed_on' => Carbon::parse($on ?? now())->toDateString(),
            'completed_by' => auth()->id(),
            'completion_notes' => $notes,
        ]);

        return $action->refresh();
    }

    /**
     * **Somebody else's confirmation**, and it refuses an action nobody has claimed to have done.
     *
     * Verifying something that has not been done is the closure §16.4's punch item and §17.2's NCR both refuse, in the
     * same words for the same reason.
     */
    public function verify(QhseAction $action, ?string $on = null, ?string $notes = null): QhseAction
    {
        if ($action->isVerified()) {
            throw new InvalidArgumentException('This action was already verified.');
        }

        if (! $action->isDone()) {
            throw new InvalidArgumentException(
                'Nobody has said this is done yet. Verification confirms somebody else\'s work — verifying an action '
                .'that has not been carried out is a closure that proves nothing.'
            );
        }

        $action->update([
            'status' => QhseAction::STATUS_VERIFIED,
            'verified_on' => Carbon::parse($on ?? now())->toDateString(),
            'verified_by' => auth()->id(),
            'verification_notes' => $notes,
        ]);

        return $action->refresh();
    }

    /** Cancelled with a reason, and kept — the same discipline as a cancelled RFI or a voided NCR. */
    public function cancel(QhseAction $action, string $reason): QhseAction
    {
        if (! $action->isLive()) {
            throw new InvalidArgumentException("This action is {$action->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Cancelling an action needs a reason. Somebody raised it against a finding, and a row that simply '
                .'disappears is the one that gets raised again next month.'
            );
        }

        $action->update(['status' => QhseAction::STATUS_CANCELLED, 'cancel_reason' => $reason]);

        return $action->refresh();
    }

    /**
     * Every action a QHSE object raised.
     *
     * @return Collection<int, QhseAction>
     */
    public function forSubject(Model $subject): Collection
    {
        return QhseAction::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderBy('due_on')
            ->get();
    }

    /**
     * **Overdue actions across every source** — the screen §17.4 exists for.
     *
     * @return Collection<int, QhseAction>
     */
    public function overdue(Job $job, ?string $asAt = null): Collection
    {
        return QhseAction::query()
            ->forJobTree($job)
            ->overdue($asAt)
            ->orderBy('due_on')
            ->get();
    }

    /**
     * Claimed done, nobody has confirmed.
     *
     * @return Collection<int, QhseAction>
     */
    public function awaitingVerification(Job $job): Collection
    {
        return QhseAction::query()->forJobTree($job)->awaitingVerification()->orderBy('completed_on')->get();
    }

    /**
     * Live actions with nobody's name against them.
     *
     * Not possible through `raise()`, which refuses one — but an import or a later edit could produce it, and an action
     * nobody owns is an action nobody does.
     *
     * @return Collection<int, QhseAction>
     */
    public function unassigned(Job $job): Collection
    {
        return QhseAction::query()
            ->forJobTree($job)
            ->live()
            ->whereNull('assignee_label')
            ->whereNull('assigned_contact_id')
            ->whereNull('assigned_user_id')
            ->get();
    }

    /**
     * **Everything overdue, from every source, in one list — and each row says which source it came from.**
     *
     * §17.4 asks for one list; §17.2 puts CAPA dates on the NCR because ISO 9001 asks for them there. Both are true, and
     * mirroring one into the other would be two sources for one date — the trap this plan refuses everywhere else. So
     * this assembles them and **names the source of every row**, which is the same discipline §17.6 demands of a
     * frequency rate's denominator: where a figure comes from more than one place, the report says which.
     *
     * @return array<int, array{source: string, reference: string, description: string, due_on: string, days_late: int, assignee: string}>
     */
    public function everythingOverdue(Job $job, ?string $asAt = null): array
    {
        $asAt ??= now()->toDateString();
        $today = Carbon::parse($asAt)->startOfDay();

        $rows = $this->overdue($job, $asAt)
            ->map(fn (QhseAction $action): array => [
                'source' => 'Action · '.$action->sourceLabel(),
                'reference' => $action->typeLabel(),
                'description' => str($action->description)->limit(80),
                'due_on' => $action->due_on->toDateString(),
                'days_late' => abs((int) $action->daysUntilDue($asAt)),
                'assignee' => $action->assigneeName(),
            ])
            ->all();

        /*
         * The NCR's own CAPA dates, read where they are rather than copied here.
         *
         * Two rows per NCR at most — corrective and preventive — and each says which it is, because "the NCR is overdue"
         * without saying which half is the sentence that makes somebody fix the pour again and leave the cause alone.
         */
        foreach (Ncr::query()->forJobTree($job)->live()->get() as $ncr) {
            foreach ([
                ['corrective', $ncr->corrective_due_on, $ncr->corrective_done_on, $ncr->corrective_action, $ncr->corrective_owner_label],
                ['preventive', $ncr->preventive_due_on, $ncr->preventive_done_on, $ncr->preventive_action, $ncr->preventive_owner_label],
            ] as [$half, $due, $done, $text, $owner]) {
                if ($due === null || $done !== null || ! $due->lt($today)) {
                    continue;
                }

                $rows[] = [
                    'source' => "NCR CAPA · {$half}",
                    'reference' => $ncr->ncr_number,
                    'description' => str($text ?? $ncr->description)->limit(80),
                    'due_on' => $due->toDateString(),
                    'days_late' => (int) $due->diffInDays($today, absolute: false),
                    'assignee' => $owner ?? $ncr->responsibleName(),
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => $b['days_late'] <=> $a['days_late']);

        return $rows;
    }
}
