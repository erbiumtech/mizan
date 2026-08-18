<?php

namespace App\Modules\ConstructionContracts\Services;

use App\Modules\ConstructionContracts\Models\ComplianceDocument;
use App\Modules\ConstructionContracts\Models\ComplianceRequirement;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Whether a subcontractor is compliant, and what that stops — `docs/construction-management-plan.md` §12.
 *
 * **Nothing here is stored.** §12 names a stored compliance status as the most dangerous silent failure on the payable
 * side: "a row with a stored `status = 'verified'` and an `expires_on` three months in the past pays a subcontractor
 * with no cover, and the screen says everything is fine". So every answer is derived from the dates, **as at the date
 * being asked about** — which is what lets a June certificate be judged on June's cover rather than August's.
 *
 * **The block is at certification, not at payment**, and §12's reasoning is worth keeping in front of whoever changes
 * it: blocking payment leaves "an approved payable in the ledger that finance cannot pay", and that is worse than a
 * refusal because the liability already exists and the stuck payment has nobody's name on it. "You may not yet certify
 * this" is a sentence somebody can act on.
 *
 * **And it lives in a service, not a form.** A rule enforced only in a Filament form "is a rule that a queue job, a
 * console command or any future API bypasses in complete silence".
 */
class ComplianceService
{
    /**
     * The requirements that apply to a contract: its own, plus the company template for anything it is silent about.
     *
     * The contract wins where both exist, so a subcontract that needs no professional indemnity can say so once rather
     * than the template having to know about every exception.
     *
     * @return Collection<string, ComplianceRequirement>
     */
    public function requirementsFor(Contract $contract): Collection
    {
        /*
         * `toBase()` before merging, and it is not a nicety.
         *
         * `Eloquent\Collection::merge()` re-keys by **primary key** and returns `array_values`, so merging two
         * collections keyed by `kind` throws the keys away and hands back a numerically indexed list. Everything
         * downstream reads `$kind => $requirement`, so the contract's override stopped overriding and every document
         * lookup missed — which surfaced as every requirement reporting `missing` while its document sat in the table.
         */
        $template = ComplianceRequirement::query()->template()->get()->keyBy('kind')->toBase();
        $own = ComplianceRequirement::query()->where('contract_id', $contract->getKey())->get()->keyBy('kind')->toBase();

        return $template->merge($own);
    }

    /**
     * Every requirement with the document that answers it and the status of that answer, as at a date.
     *
     * A requirement with no document at all comes back as `missing` rather than being absent from the list — the
     * absence is the finding, and a report that only listed the documents it had would be a report of the good news.
     *
     * @return array<int, array{
     *     requirement: ComplianceRequirement,
     *     document: ComplianceDocument|null,
     *     status: string,
     *     blocks_certification: bool,
     *     days_until_expiry: int|null,
     * }>
     */
    public function statusFor(Contract $contract, string|Carbon|null $asOf = null): array
    {
        $at = $asOf ? Carbon::parse($asOf) : now();

        $documents = ComplianceDocument::query()
            ->where(function ($query) use ($contract): void {
                // The subcontractor's company-level documents, plus anything specific to this contract.
                $query->where('contract_id', $contract->getKey())
                    ->orWhere(fn ($q) => $q->whereNull('contract_id')->where('contact_id', $contract->contact_id));
            })
            ->orderByDesc('expires_on')
            ->get()
            ->groupBy('kind');

        $rows = [];

        foreach ($this->requirementsFor($contract) as $kind => $requirement) {
            /*
             * The **best** document of that kind, not the newest: a subcontractor who sends last year's certificate
             * again should not displace the current one, and picking by expiry date would let a document with no
             * expiry at all outrank a live policy.
             */
            $candidates = $documents->get($kind, collect());

            $document = $candidates
                ->sortByDesc(fn (ComplianceDocument $d): int => match (true) {
                    ! $d->coversPeriod($at) => 0,
                    $d->satisfiesOn($at, $requirement->grace_days) => 3,
                    $d->statusOn($at, $requirement->grace_days) === ComplianceDocument::STATUS_UNVERIFIED => 2,
                    default => 1,
                })
                ->first();

            $status = $document === null
                ? ComplianceDocument::STATUS_MISSING
                : $document->statusOn($at, $requirement->grace_days);

            $satisfied = $document !== null
                && $document->coversPeriod($at)
                && $document->satisfiesOn($at, $requirement->grace_days)
                && $this->meetsMinimumCover($document, $requirement);

            $rows[] = [
                'requirement' => $requirement,
                'document' => $document,
                'status' => $satisfied ? $status : ($status === ComplianceDocument::STATUS_VALID
                    // A live policy for too little money is not compliance, and calling it valid would hide that.
                    ? 'insufficient_cover'
                    : $status),
                'blocks_certification' => ! $satisfied && $requirement->blocksCertification(),
                'days_until_expiry' => $document?->daysUntilExpiry($at),
            ];
        }

        return $rows;
    }

    /**
     * Where the contract specifies a sum insured, a token policy does not satisfy it.
     *
     * Worth checking rather than trusting: a subcontractor asked for ten million of public liability who produces a
     * one-million policy has produced a document, and a register that only asked whether a document existed would pass
     * it.
     */
    private function meetsMinimumCover(ComplianceDocument $document, ComplianceRequirement $requirement): bool
    {
        if ($requirement->minimum_cover === null) {
            return true;
        }

        return (float) ($document->amount_covered ?? 0) >= (float) $requirement->minimum_cover;
    }

    /**
     * What would stop a certificate being issued, in the words the refusal uses.
     *
     * @return array<int, string>
     */
    public function blockers(Contract $contract, string|Carbon|null $asOf = null): array
    {
        $blockers = [];

        foreach ($this->statusFor($contract, $asOf) as $row) {
            if (! $row['blocks_certification']) {
                continue;
            }

            $status = str_replace('_', ' ', $row['status']);
            $days = $row['days_until_expiry'];

            $blockers[] = $row['requirement']->label().' — '.$status
                .($status === 'expired' && $days !== null ? ' '.abs($days).' days ago' : '');
        }

        return $blockers;
    }

    /**
     * Whether a certificate may be issued on compliance grounds.
     *
     * Judged as at the certificate's **valuation date**, not today: a June certificate issued in August has to be
     * judged on the cover that was in force in June, and asking about today would refuse a payment for work that was
     * properly covered when it was done.
     */
    public function permitsCertification(PaymentCertificate $certificate): bool
    {
        if ($certificate->compliance_override_at !== null) {
            return true;
        }

        return $this->blockers($certificate->contract, $certificate->period_end) === [];
    }

    /**
     * Override the block for **this certificate**, with a reason.
     *
     * §12: "a system with no override is a system people work around with a spreadsheet, and then the register is
     * decorative". The reason is mandatory and the override is per certificate rather than per document — certifying
     * despite lapsed cover once is a judgement about one month, and recording it on the document would silently clear
     * every later certificate too.
     */
    public function override(PaymentCertificate $certificate, string $reason): PaymentCertificate
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Overriding a compliance block needs a reason. It is the record that this payment was certified '
                .'knowing the cover was not in place, and somebody has to own that.'
            );
        }

        if ($this->blockers($certificate->contract, $certificate->period_end) === []) {
            throw new InvalidArgumentException(
                "Nothing is blocking {$certificate->certificate_number}, so there is nothing to override. Recording an "
                .'override anyway would put a decision on the file about a risk nobody took.'
            );
        }

        $certificate->update([
            'compliance_override_at' => now(),
            'compliance_override_by' => auth()->id(),
            'compliance_override_reason' => $reason,
        ]);

        return $certificate->refresh();
    }

    /** Verify a document: somebody has read it, which is not the same as its having arrived. */
    public function verify(ComplianceDocument $document): ComplianceDocument
    {
        $document->update([
            'verified_by' => auth()->id(),
            'verified_at' => now(),
            'received_on' => $document->received_on ?? now()->toDateString(),
        ]);

        return $document->refresh();
    }

    /**
     * Waive the requirement this document answers, with a reason.
     *
     * Different from overriding a certificate: this says "we have accepted that this subcontractor will not produce
     * this", which is a standing decision, where an override is about one payment.
     */
    public function waive(ComplianceDocument $document, string $reason): ComplianceDocument
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Waiving a compliance document needs a reason.');
        }

        $document->update([
            'waived_by' => auth()->id(),
            'waived_at' => now(),
            'waiver_reason' => $reason,
        ]);

        return $document->refresh();
    }

    /**
     * Documents whose expiry has crossed a threshold that has not been warned about yet.
     *
     * The threshold pattern is `employee_documents`': store which one has been warned about "so the next run only
     * notifies when the answer changes" — otherwise a policy expiring in sixty days produces sixty identical emails and
     * the sixty-first is ignored.
     *
     * @return Collection<int, array{document: ComplianceDocument, days: int, threshold: int}>
     */
    public function dueForWarning(?string $asOf = null): Collection
    {
        return ComplianceDocument::query()
            ->needingAttention()
            ->get()
            ->map(function (ComplianceDocument $document) use ($asOf): ?array {
                $days = $document->daysUntilExpiry($asOf);

                if ($days === null) {
                    return null;
                }

                // The tightest threshold this document has now crossed. Expired documents warn at the 1-day mark and
                // then stop: the register is where an expired policy is chased, not the inbox.
                $threshold = collect(ComplianceDocument::WARN_THRESHOLDS)
                    ->filter(fn (int $t): bool => $days <= $t)
                    ->min();

                if ($threshold === null) {
                    return null;
                }

                // Already warned at this threshold or a tighter one.
                if ($document->expiry_notified_at_days !== null && $document->expiry_notified_at_days <= $threshold) {
                    return null;
                }

                return ['document' => $document, 'days' => $days, 'threshold' => $threshold];
            })
            ->filter()
            ->values();
    }

    /** Record that a warning went out, so the next run only speaks when the answer changes. */
    public function markWarned(ComplianceDocument $document, int $threshold): void
    {
        $document->update(['expiry_notified_at_days' => $threshold]);
    }
}
