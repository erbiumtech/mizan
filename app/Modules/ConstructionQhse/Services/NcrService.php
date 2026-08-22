<?php

namespace App\Modules\ConstructionQhse\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\Ncr;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Non-conformance, CAPA and close-out — `docs/construction-management-plan.md` §17.2.
 *
 * **Nothing in this class withholds money.** §17.2: "an NCR never deducts automatically. It *proposes*; the
 * certification service **offers** the deduction as a row on the certificate that a human confirms and signs for."
 * `proposeDeduction()` is the strongest verb here, and `deduction_certificate_id` — the column that says a human took
 * the proposal up — is written by `ConstructionContracts`, never by this module. That direction is the whole design, and
 * it is visible in the import graph.
 *
 * Five other rules live here:
 *
 *  - **A disposition is chosen, never defaulted.** It is "the field that decides whether money changes hands", so a new
 *    NCR has none and the register can ask which ones nobody has answered for.
 *  - **A closure needs a verification.** §17.2: close-out points at the re-inspection, "which is what makes a closure
 *    evidence rather than an assertion". So `close()` refuses without one, and `verify()` refuses a re-inspection that
 *    did not pass.
 *  - **Corrective and preventive action are recorded separately**, because ISO 9001:2015 dropped preventive action as a
 *    clause and every client's quality manual still demands both — and merging them produces NCRs whose preventive
 *    action restates the fix.
 *  - **Voiding is a status with a reason, not a deletion.** An NCR somebody raised on a walk-round and then agreed was
 *    not a nonconformity is a fact worth keeping; the number stays used, exactly as §16.2's cancelled RFI does.
 *  - **A concession needs its reference.** Asking the client to accept nonconforming work and not recording what they
 *    said is how a job ends up with an as-built nobody can defend.
 */
class NcrService
{
    /**
     * Raise one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raise(Job $job, array $attributes): Ncr
    {
        if (trim((string) ($attributes['description'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'An NCR needs describing. "Non-conformance" against a location is a row the trade cannot price and the '
                .'client cannot assess.'
            );
        }

        if (! array_key_exists($attributes['severity'] ?? Ncr::SEVERITY_MINOR, Ncr::SEVERITIES)) {
            throw new InvalidArgumentException('An NCR needs a severity: minor, major or critical.');
        }

        // Deliberately dropped: a disposition is chosen deliberately, and one arriving with the raise would settle the
        // commercially significant question before anybody had looked at the work.
        unset($attributes['disposition'], $attributes['status']);

        return TenantTransaction::run(fn (): Ncr => Ncr::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'ncr_number' => $attributes['ncr_number'] ?? $this->nextNumber($job),
            'raised_on' => Carbon::parse($attributes['raised_on'] ?? now())->toDateString(),
        ])));
    }

    /**
     * Raise one from a failed inspection, carrying the traceability the standard asks for.
     *
     * The ITP row travels with it — §17.2 names `itp_activity_id` as "the traceability back to the ITP row that the
     * standard actually asks for" — and so does the location, because an NCR that cannot say where it is is an NCR
     * nobody can find.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function raiseFromInspection(Inspection $inspection, array $attributes = []): Ncr
    {
        if (! $inspection->hasFailed()) {
            throw new InvalidArgumentException(
                "{$inspection->displayName()} is {$inspection->status}. An NCR raised from an inspection that did not "
                .'fail is an NCR whose own evidence contradicts it — raise it against the job directly if the '
                .'nonconformity was found some other way.'
            );
        }

        $ncr = $this->raise(Job::query()->findOrFail($inspection->job_id), array_merge([
            'description' => 'Failed inspection: '.$inspection->activity_description,
            'source' => 'inspection',
            'location_id' => $inspection->location_id,
            'contract_item_id' => $inspection->contract_item_id,
            'activity_id' => $inspection->activity_id,
        ], $attributes, [
            'inspection_id' => $inspection->getKey(),
            'itp_activity_id' => $inspection->itp_activity_id,
        ]));

        // The inspection points back, so the register can say which failures produced an NCR and which did not.
        $inspection->update(['ncr_id' => $ncr->getKey()]);

        return $ncr;
    }

    /**
     * `NCR-1` upward per job.
     *
     * Read off the trailing digits of the highest existing number: nothing here is deleted, so max-plus-one has no gaps
     * — and a register quoted by number must not have any.
     */
    public function nextNumber(Job $job): string
    {
        $used = Ncr::query()
            ->where('job_id', $job->getKey())
            ->pluck('ncr_number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return 'NCR-'.($used + 1);
    }

    /**
     * Edit it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Ncr $ncr, array $attributes): Ncr
    {
        if ($ncr->isClosed()) {
            throw new InvalidArgumentException(
                "{$ncr->displayName()} is {$ncr->status}. Raise a new NCR if the nonconformity has recurred — a defect "
                .'that comes back after being signed off is its own finding, and overwriting the closed one hides that '
                .'it ever happened.'
            );
        }

        // Each of these has its own act and its own rule.
        unset(
            $attributes['status'], $attributes['disposition'], $attributes['verified_on'], $attributes['verified_by'],
            $attributes['closed_on'], $attributes['deduction_certificate_id'],
        );

        $ncr->update($attributes);

        return $ncr->refresh();
    }

    /**
     * **Disposition it** — ISO 9001's control of nonconforming output.
     *
     * A concession needs its reference: asking the client to accept nonconforming work and not recording what they said
     * is how a job ends up with an as-built nobody can defend.
     */
    public function disposition(Ncr $ncr, string $disposition, ?string $concessionReference = null, ?string $on = null): Ncr
    {
        if ($ncr->isClosed()) {
            throw new InvalidArgumentException("{$ncr->displayName()} is {$ncr->status}.");
        }

        if (! array_key_exists($disposition, Ncr::DISPOSITIONS)) {
            throw new InvalidArgumentException("{$disposition} is not one of ISO 9001's dispositions.");
        }

        if ($disposition === Ncr::DISPOSITION_CONCESSION && trim((string) $concessionReference) === '') {
            throw new InvalidArgumentException(
                'A concession needs its reference. Asking the client to accept nonconforming work and not recording '
                .'what they said is how a job ends up with an as-built nobody can defend.'
            );
        }

        $ncr->update([
            'disposition' => $disposition,
            'concession_reference' => $concessionReference,
            'dispositioned_on' => Carbon::parse($on ?? now())->toDateString(),
            'dispositioned_by' => auth()->id(),
            'status' => $ncr->status === Ncr::STATUS_OPEN ? Ncr::STATUS_DISPOSITIONED : $ncr->status,
        ]);

        return $ncr->refresh();
    }

    /**
     * Record CAPA.
     *
     * The two pairs are written together but kept apart, and marking the corrective action done is what moves the
     * status: the work has been put right, and the closure still needs a re-inspection behind it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordCapa(Ncr $ncr, array $attributes): Ncr
    {
        if ($ncr->isClosed()) {
            throw new InvalidArgumentException("{$ncr->displayName()} is {$ncr->status}.");
        }

        $ncr->update($attributes);
        $ncr->refresh();

        if ($ncr->corrective_done_on !== null && $ncr->status === Ncr::STATUS_DISPOSITIONED) {
            $ncr->update(['status' => Ncr::STATUS_ACTION_TAKEN]);
        }

        return $ncr->refresh();
    }

    /**
     * **Verify the fix against a re-inspection** — what makes a closure evidence rather than an assertion.
     *
     * The re-inspection has to have passed, and it has to be a different inspection from the one that found the
     * nonconformity: verifying an NCR against the failure that raised it is a closure that proves the opposite of what
     * it claims.
     */
    public function verify(Ncr $ncr, Inspection $inspection, ?string $on = null): Ncr
    {
        if ($ncr->isClosed()) {
            throw new InvalidArgumentException("{$ncr->displayName()} is {$ncr->status}.");
        }

        if ($inspection->getKey() === $ncr->inspection_id) {
            throw new InvalidArgumentException(
                'That is the inspection that found the nonconformity. A closure verified against its own failure '
                .'proves the opposite of what it claims — re-inspect the work.'
            );
        }

        if (! $inspection->wasAccepted()) {
            throw new InvalidArgumentException(
                "{$inspection->displayName()} is {$inspection->status}, so the work is not right yet. An NCR is "
                .'verified against a re-inspection that passed.'
            );
        }

        $ncr->update([
            'verification_inspection_id' => $inspection->getKey(),
            'verified_by' => auth()->id(),
            'verified_on' => Carbon::parse($on ?? $inspection->inspected_on ?? now())->toDateString(),
            'status' => Ncr::STATUS_VERIFIED,
        ]);

        return $ncr->refresh();
    }

    /**
     * Close it, which requires a verification.
     *
     * §17.2: close-out points at the re-inspection. A closure with nothing behind it is an assertion, and an assertion
     * is what an audit finds.
     */
    public function close(Ncr $ncr, ?string $on = null): Ncr
    {
        if ($ncr->isClosed()) {
            throw new InvalidArgumentException("{$ncr->displayName()} is already {$ncr->status}.");
        }

        if (! $ncr->isVerified()) {
            throw new InvalidArgumentException(
                "{$ncr->displayName()} has no verification against it. Close-out points at a re-inspection, which is "
                .'what makes a closure evidence rather than an assertion — verify it first, or void it with a reason.'
            );
        }

        $ncr->update([
            'status' => Ncr::STATUS_CLOSED,
            'closed_on' => Carbon::parse($on ?? now())->toDateString(),
        ]);

        return $ncr->refresh();
    }

    /**
     * Void it, with a reason, keeping the number.
     *
     * An NCR raised on a walk-round and then agreed not to be a nonconformity is a fact worth keeping — and the number
     * stays used, exactly as §16.2's cancelled RFI does, because a gap in a register quoted by number is
     * indistinguishable from a removal somebody wanted.
     */
    public function void(Ncr $ncr, string $reason): Ncr
    {
        if ($ncr->isClosed()) {
            throw new InvalidArgumentException("{$ncr->displayName()} is already {$ncr->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Voiding an NCR needs a reason. Somebody raised it against a requirement, and "not a nonconformity" '
                .'with no explanation is the row that gets raised again next month.'
            );
        }

        $ncr->update([
            'status' => Ncr::STATUS_VOID,
            'void_reason' => $reason,
            'closed_on' => now()->toDateString(),
        ]);

        return $ncr->refresh();
    }

    /**
     * **Propose a deduction. This does not withhold anything.**
     *
     * §17.2's rule in one method: the NCR proposes, and the certification service offers the row for a human to confirm
     * and sign. Nothing here writes `deduction_certificate_id` — that column records what somebody else decided, and
     * this module never touches it.
     */
    public function proposeDeduction(Ncr $ncr, float $amount, ?string $note = null): Ncr
    {
        if ($ncr->isClosed()) {
            throw new InvalidArgumentException(
                "{$ncr->displayName()} is {$ncr->status}, so there is nothing left to withhold against."
            );
        }

        if ($amount <= 0.0) {
            throw new InvalidArgumentException(
                'A proposed deduction needs a figure. A flag with no amount is a withholding nobody can put on a '
                .'certificate, which means it will not be withheld at all.'
            );
        }

        if ($ncr->deductionWasTaken()) {
            throw new InvalidArgumentException(
                "{$ncr->displayName()} is already on a certificate. Raise a variation or a further NCR rather than "
                .'changing a figure a client has been sent.'
            );
        }

        $ncr->update([
            'deduct_from_payment' => true,
            'deduction_amount' => round($amount, 2),
            'notes' => $note === null ? $ncr->notes : trim(($ncr->notes ? $ncr->notes."\n\n" : '').$note),
        ]);

        return $ncr->refresh();
    }

    /** Withdraw a proposal that has not been taken up. */
    public function withdrawDeduction(Ncr $ncr): Ncr
    {
        if ($ncr->deductionWasTaken()) {
            throw new InvalidArgumentException(
                "{$ncr->displayName()} is already on a certificate, so the proposal is no longer a proposal."
            );
        }

        $ncr->update(['deduct_from_payment' => false, 'deduction_amount' => null]);

        return $ncr->refresh();
    }

    /**
     * **The proposal queue** — live NCRs proposing a withholding that no certificate has taken up.
     *
     * This is what §17.2's offer reads. It is a *list of suggestions*, and the only thing that turns one into money is a
     * person putting it on a certificate and signing.
     *
     * @return Collection<int, Ncr>
     */
    public function proposedDeductions(Job $job): Collection
    {
        return Ncr::query()
            ->forJobTree($job)
            ->proposingDeduction()
            ->orderBy('raised_on')
            ->get();
    }

    /**
     * NCRs nobody has dispositioned — the commercially significant question left unanswered.
     *
     * @return Collection<int, Ncr>
     */
    public function awaitingDisposition(Job $job): Collection
    {
        return Ncr::query()->forJobTree($job)->awaitingDisposition()->orderBy('raised_on')->get();
    }

    /**
     * **Accepted nonconforming work with no reduction proposed** — the exposure this register carries.
     *
     * *Use as is* and *concession requested* are the client accepting less than the specification. Where nobody has
     * proposed a figure and no back charge exists, the concession was given away for nothing.
     *
     * @return Collection<int, Ncr>
     */
    public function acceptedWithoutReduction(Job $job): Collection
    {
        return Ncr::query()
            ->forJobTree($job)
            ->whereIn('disposition', Ncr::COMMERCIAL_DISPOSITIONS)
            ->where('deduct_from_payment', false)
            ->whereNull('back_charge_id')
            ->get();
    }

    /**
     * **Corrective action done, preventive action outstanding** — the commonest CAPA failure.
     *
     * The pour gets fixed and the reason it happened does not. Filtered in PHP because it is a comparison between two
     * nullable date columns and a text field, and Phase 9e recorded why that does not belong in SQL here.
     *
     * @return Collection<int, Ncr>
     */
    public function fixedButNotPrevented(Job $job): Collection
    {
        return Ncr::query()
            ->forJobTree($job)
            ->live()
            ->whereNotNull('corrective_done_on')
            ->whereNull('preventive_done_on')
            ->get()
            ->filter(fn (Ncr $ncr): bool => $ncr->fixedButNotPrevented())
            ->values();
    }

    /**
     * Overdue CAPA, corrective or preventive, in one list.
     *
     * §17.4 builds the one actions table that answers this across every QHSE object; until then this is the NCR's own
     * answer, and it is deliberately a single list rather than two — "everything overdue" is the safety manager's one
     * genuinely useful screen.
     *
     * @return Collection<int, Ncr>
     */
    public function overdueActions(Job $job, ?string $asAt = null): Collection
    {
        return Ncr::query()
            ->forJobTree($job)
            ->live()
            ->get()
            ->filter(fn (Ncr $ncr): bool => $ncr->correctiveOverdue($asAt) || $ncr->preventiveOverdue($asAt))
            ->values();
    }
}
