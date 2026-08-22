<?php

namespace App\Modules\ConstructionContracts\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionQhse\Models\Ncr;
use App\Support\TenantTransaction;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Offering an NCR's proposed deduction on a certificate — `docs/construction-management-plan.md` §17.2.
 *
 * **The whole of this class is the difference between offering and applying**, and §17.2 spends a paragraph on why:
 *
 * > "An NCR never deducts automatically. It *proposes*; the certification service **offers** the deduction as a row on
 * > the certificate that a human confirms and signs for. FIDIC 14.6 permits the Engineer to withhold; it does not
 * > require it. A deduction appearing on a certificate that nobody decided on is the fastest available route to a
 * > dispute, and it will be the contractor's dispute, because the client's copy has already left the building."
 *
 * So there is no scheduled job here, no observer on the NCR, and nothing that runs while a certificate is being
 * assembled. `offersFor()` is a **read** — a list somebody looks at — and `take()` writes a deduction row only when
 * called with a certificate, an amount and a person behind it. The NCR's `deduction_certificate_id` is written *here*,
 * on this side of the boundary, which is what makes the direction visible in the import graph rather than merely
 * asserted.
 *
 * §17.2 names the precedent in this repository, and it is exact: `final_settlements.payslip_id` is a nullable column
 * recording which existing path paid a settlement, *which the settlement itself never writes*. Same shape, same reason,
 * and `ModuleBoundaryTest`'s commentary on that column is where it was first written down.
 *
 * **A guarded coupling**, like every cross-sibling path out of this module: `construction_contracts` requires only
 * `construction`, so a contractor certifying without a quality module gets no offers and a certificate exactly as it was
 * before this class existed. The direction is forced rather than chosen — pointing `construction_qhse` at contracts
 * instead would make the pair a cycle, and a cycle cannot be a composer dependency.
 */
class NcrDeductionOffer
{
    /**
     * Whether there is anything to offer at all.
     *
     * The module check and nothing more: this class reads NCRs, and without them there is no list.
     */
    public function isAvailable(): bool
    {
        return modules()->enabled('construction_qhse');
    }

    /**
     * **The offers for a contract's job — a list to look at, not a thing that happens.**
     *
     * Live NCRs proposing a withholding that no certificate has taken up. Nothing is written by calling this, and that
     * is the point: a screen shows them, a person decides, and `take()` records the decision.
     *
     * Empty without the quality module, which is the graceful half of §18.1 — a certificate with no offers is exactly
     * the certificate this application produced before Phase 10b.
     *
     * @return Collection<int, Ncr>
     */
    public function offersFor(Contract $contract): Collection
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        $job = $contract->relationLoaded('job')
            ? $contract->job
            : Job::query()->find($contract->job_id);

        if ($job === null) {
            return collect();
        }

        return Ncr::query()
            ->forJobTree($job)
            ->proposingDeduction()
            // A proposal raised under a different contract on the same job is not this certificate's business.
            ->where(fn ($query) => $query->whereNull('contract_id')->orWhere('contract_id', $contract->getKey()))
            ->orderBy('raised_on')
            ->get();
    }

    /**
     * The total on offer, for a screen that wants one figure.
     *
     * Deliberately *not* used to reduce anything. It is the size of the decision somebody is being asked to make.
     */
    public function totalOffered(Contract $contract): float
    {
        return round((float) $this->offersFor($contract)->sum('deduction_amount'), 2);
    }

    /**
     * **Take up an offer: write the deduction row, and record on the NCR that a human did.**
     *
     * The amount is a parameter rather than read from the NCR, because the person signing may withhold less than was
     * proposed — which is the ordinary outcome of a conversation about it, and a version of this that copied the
     * proposal would make the conversation unrecordable.
     *
     * Refused on an issued certificate: a client's copy has already left the building, and §10's whole treatment of
     * certificates rests on an issued one being the document both parties hold.
     */
    public function take(PaymentCertificate $certificate, Ncr $ncr, ?float $amount = null, ?string $reason = null): CertificateDeduction
    {
        if (! $this->isAvailable()) {
            throw new InvalidArgumentException(
                'Site quality is not licensed, so there are no non-conformance proposals to take up.'
            );
        }

        if ($certificate->isIssued()) {
            throw new InvalidArgumentException(
                "{$certificate->certificate_number} has been issued and the client has a copy. A deduction decided "
                .'afterwards belongs on the next certificate.'
            );
        }

        if (! $ncr->proposesDeduction()) {
            throw new InvalidArgumentException(
                "{$ncr->displayName()} is not proposing a deduction — it is {$ncr->status}"
                .($ncr->deductionWasTaken() ? ' and already on a certificate.' : ' with no amount proposed.')
            );
        }

        $withheld = round($amount ?? (float) $ncr->deduction_amount, 2);

        if ($withheld <= 0.0) {
            throw new InvalidArgumentException(
                'A deduction of nothing is not a decision. Withdraw the proposal on the NCR instead.'
            );
        }

        if ($withheld > (float) $ncr->deduction_amount) {
            throw new InvalidArgumentException(
                'That is more than the NCR proposed. Withholding beyond what the quality team assessed is a decision '
                .'somebody has to make on its own terms — raise it as its own deduction, so the certificate says where '
                .'the figure came from.'
            );
        }

        return TenantTransaction::run(function () use ($certificate, $ncr, $withheld, $reason): CertificateDeduction {
            $deduction = $certificate->deductions()->create([
                'kind' => CertificateDeduction::KIND_NCR,
                'description' => "Non-conformance {$ncr->ncr_number}: ".str($ncr->description)->limit(80)
                    .($reason ? " — {$reason}" : ''),
                // Negative, as every deduction on a certificate is: the sign is what makes the certificate's arithmetic
                // one sum rather than a pipeline of subtractions.
                'amount' => -abs($withheld),
                /*
                 * **`is_automatic` false, and `approved_by` filled in — this is the whole of §17.2 in two columns.**
                 *
                 * `CertificateDeduction::AUTOMATIC_KINDS` is the set this module computes for itself: retention, the
                 * advance recovery, previous certificates. An NCR deduction is deliberately *not* in it, because a human
                 * decided this one and their name is against it. A row marked automatic would be a withholding the
                 * certificate claims it worked out — which is exactly the row §17.2 says starts the dispute.
                 */
                'is_automatic' => false,
                'approved_by' => auth()->id(),
                // Where the figure came from, so a certifier reading the certificate in a year can trace it back.
                'source_type' => Ncr::class,
                'source_id' => $ncr->getKey(),
            ]);

            /*
             * **Written here, on this side of the boundary.**
             *
             * The NCR records which certificate took its proposal up, and the quality module never writes this column —
             * which is what makes "a proposal, not a posting" a structural claim rather than a comment.
             */
            $ncr->update(['deduction_certificate_id' => $certificate->getKey()]);

            return $deduction->refresh();
        });
    }
}
