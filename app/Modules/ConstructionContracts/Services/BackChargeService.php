<?php

namespace App\Modules\ConstructionContracts\Services;

use App\Modules\ConstructionContracts\Models\BackCharge;
use App\Modules\ConstructionContracts\Models\CertificateDeduction;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Support\ModuleMap;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Back-charges and the notice that makes them recoverable — `docs/construction-management-plan.md` §12.
 *
 * Four rules live here, and each is a way a back-charge is money the company thinks it has and does not.
 *
 *  - **Notice before deduction.** `apply()` refuses a draft outright. §12: almost every subcontract requires notice
 *    before a back-charge may be deducted, and an incurred-but-unnotified back-charge is money the company will not get
 *    and does not yet know it has lost. The register can be *queried* on that state, which is the only reason it stops
 *    being a habit somebody has.
 *  - **Nothing applies itself.** §16.5 settles this for NCRs and it holds identically here: this service *offers* the
 *    charges a certificate could take, and a human applies one. A deduction nobody decided on is the fastest route to a
 *    dispute, and it will be the contractor's dispute, because the other party's copy has already left the building.
 *  - **Draft computes, notice freezes.** The same rule the certificate keeps (§10). The notified total is the figure the
 *    subcontractor was told; a total that moved with a later edit to the markup would make the notice worthless. After
 *    notice the only way the number changes is `agreed_amount`, which sits *beside* the original rather than over it.
 *  - **The deduction is the only writer of money.** Applying writes one `back_charge` row through
 *    `CertificationService::addDeduction()`, so the sign convention, the draft-only rule and the recompute all stay in
 *    the one place that owns them.
 *
 * **What this service deliberately does not do is credit the job.** The cost being charged back was recorded when the
 * company incurred it — a labour record, a plant log, a material issue — and the recovery reaches the books through the
 * certificate's invoice (§10.4). Whether the job's cost report should also show the recovery against the code that
 * carried the cost is §4's question, and §4's reconciliation is Phase 11. Writing a cost credit from here would post the
 * same money twice with nothing disagreeing, which is the failure §4.1 exists to prevent.
 */
class BackChargeService
{
    public function __construct(private CertificationService $certification) {}

    /**
     * Raise a back-charge in draft.
     *
     * Draft because the notice has not been served, and the difference is the point: raising the charge is bookkeeping,
     * serving notice is a contractual act with a date the subcontract measures.
     *
     * @param  array<string, mixed>  $attributes  `kind`, `description`, `amount`, `markup_percent`, `incurred_on`,
     *                                            `source_type`, `source_id`, `notes`
     */
    public function create(Contract $contract, array $attributes = []): BackCharge
    {
        if ($contract->side !== Contract::SIDE_PAYABLE) {
            throw new InvalidArgumentException(
                "{$contract->contract_number} is a receivable contract. A back-charge is something this company "
                .'recovers from a subcontractor by deducting it from their payment — against an employer it would be a '
                .'claim, which is a variation or a certificate deduction of its own kind.'
            );
        }

        if ($contract->isDraft()) {
            throw new InvalidArgumentException(
                "{$contract->contract_number} is not executed. There is no payment to deduct a back-charge from yet."
            );
        }

        return TenantTransaction::run(function () use ($contract, $attributes): BackCharge {
            $charge = BackCharge::create($attributes + [
                'contract_id' => $contract->getKey(),
                'job_id' => $contract->job_id,
                'reference' => $this->nextReference($contract),
                'status' => BackCharge::STATUS_DRAFT,
            ]);

            return $this->recompute($charge);
        });
    }

    /**
     * The next number in the contract's series.
     *
     * Read off the trailing segment only, the same way `ContractService::nextContractNumber()` does and for the reason
     * recorded there: stripping every non-digit from a reference that carries a code eats the series.
     */
    public function nextReference(Contract $contract): string
    {
        $used = BackCharge::query()
            ->where('contract_id', $contract->getKey())
            ->pluck('reference')
            ->map(fn (string $reference): int => (int) (preg_match('/(\d+)$/', $reference, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return 'BC-'.($used + 1);
    }

    /**
     * Recompute the total from the cost and the markup.
     *
     * **Only while the charge is a draft.** Once notice is served the total is what the subcontractor was told, and a
     * figure that drifted afterwards would make every notice a document nobody could rely on — the same reason
     * `CertificationService::recompute()` refuses an issued certificate.
     */
    public function recompute(BackCharge $charge): BackCharge
    {
        if (! $charge->isDraft()) {
            return $charge;
        }

        $charge->update(['total_amount' => $charge->computedTotal()]);

        return $charge->refresh();
    }

    /**
     * Serve notice, which is what makes the charge recoverable.
     *
     * The date is required and is the subcontractor's date, not today's: notice served on the 3rd and recorded on the
     * 11th is notice served on the 3rd, and a system that stamped `now()` would quietly move every notice inside the
     * contractual window it is being measured against.
     */
    public function notify(BackCharge $charge, ?string $notifiedOn = null, ?int $noticeDocumentId = null): BackCharge
    {
        if ($charge->status !== BackCharge::STATUS_DRAFT) {
            throw new InvalidArgumentException(
                "{$charge->reference} is already {$charge->status}. Notice is served once, and the date on the row is "
                .'the date the subcontract is measured against.'
            );
        }

        if (round((float) $charge->total_amount, 2) <= 0.0) {
            throw new InvalidArgumentException(
                "{$charge->reference} has no value. Serving notice of a back-charge of nothing starts a contractual "
                .'clock over an amount nobody can respond to.'
            );
        }

        $on = $notifiedOn ? Carbon::parse($notifiedOn) : now();

        if ($charge->incurred_on && $on->lt($charge->incurred_on)) {
            throw new InvalidArgumentException(
                "Notice cannot predate the work: {$charge->reference} was incurred on "
                .$charge->incurred_on->format('d M Y').'.'
            );
        }

        $charge->update([
            'status' => BackCharge::STATUS_NOTIFIED,
            'notified_on' => $on->toDateString(),
            'notified_by' => auth()->id(),
            'notice_document_id' => $noticeDocumentId ?? $charge->notice_document_id,
        ]);

        return $charge->refresh();
    }

    /**
     * Record that the subcontractor contests it.
     *
     * Recorded, not resolved. A disputed back-charge is still deductible under most subcontracts — the argument goes
     * where the contract says arguments go — and a register that hid the contested ones would report an exposure the
     * company could not defend.
     */
    public function dispute(BackCharge $charge, string $reason, ?string $on = null): BackCharge
    {
        if (! $charge->isNotified()) {
            throw new InvalidArgumentException(
                "{$charge->reference} has not been notified, so there is nothing for the subcontractor to dispute."
            );
        }

        if (in_array($charge->status, [BackCharge::STATUS_APPLIED, BackCharge::STATUS_WITHDRAWN], true)) {
            throw new InvalidArgumentException("{$charge->reference} is {$charge->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A dispute needs the subcontractor\'s reason. "Disputed" with no grounds is a row nobody can answer.'
            );
        }

        $charge->update([
            'status' => BackCharge::STATUS_DISPUTED,
            'disputed_on' => ($on ? Carbon::parse($on) : now())->toDateString(),
            'dispute_reason' => $reason,
        ]);

        return $charge->refresh();
    }

    /**
     * Settle it, possibly for less than was notified.
     *
     * The agreed figure is stored **beside** the notified total rather than over it. "Notified 240,000, settled at
     * 180,000" is the fact somebody needs at final account; one column loses the first half, and with it the reason the
     * account does not add up to what the notices said.
     */
    public function agree(BackCharge $charge, ?float $agreedAmount = null, ?string $on = null): BackCharge
    {
        if (! $charge->isNotified()) {
            throw new InvalidArgumentException(
                "{$charge->reference} has not been notified. Agreeing a charge the subcontractor has not been told "
                .'about records a settlement of a negotiation that never happened.'
            );
        }

        if (in_array($charge->status, [BackCharge::STATUS_APPLIED, BackCharge::STATUS_WITHDRAWN], true)) {
            throw new InvalidArgumentException("{$charge->reference} is {$charge->status}.");
        }

        $amount = $agreedAmount === null ? null : round($agreedAmount, 2);

        if ($amount !== null && $amount < 0) {
            throw new InvalidArgumentException('An agreed back-charge cannot be negative.');
        }

        if ($amount !== null && $amount > round((float) $charge->total_amount, 2) + 0.001) {
            throw new InvalidArgumentException(
                'Agreeing '.number_format($amount, 2).' where notice was served for '
                .number_format((float) $charge->total_amount, 2).'. A settlement above the notified figure is a new '
                .'charge and needs its own notice — otherwise the subcontractor is deducted for something they were '
                .'never told about.'
            );
        }

        $charge->update([
            'status' => BackCharge::STATUS_AGREED,
            'agreed_amount' => $amount,
            'agreed_on' => ($on ? Carbon::parse($on) : now())->toDateString(),
            'agreed_by' => auth()->id(),
        ]);

        return $charge->refresh();
    }

    /**
     * **Apply it to a certificate**, which is where the money actually comes off.
     *
     * The refusal on an un-notified charge is §12's rule and the reason this table exists. Everything else here is the
     * ordinary shape: one deduction row, negative by the convention stated once in §10.3, carrying the morph back to
     * this charge so the certificate can be read backwards.
     */
    public function apply(BackCharge $charge, PaymentCertificate $certificate): CertificateDeduction
    {
        if ($charge->status === BackCharge::STATUS_DRAFT) {
            throw new InvalidArgumentException(
                "{$charge->reference} has not been notified. Almost every subcontract requires notice before a "
                .'back-charge may be deducted, so deducting it now is a payment the subcontractor can recover — and '
                .'the company would have spent the money twice. Serve the notice first.'
            );
        }

        if ($charge->status === BackCharge::STATUS_WITHDRAWN) {
            throw new InvalidArgumentException("{$charge->reference} was withdrawn.");
        }

        if ($charge->isApplied()) {
            throw new InvalidArgumentException(
                "{$charge->reference} was already deducted on "
                .($charge->appliedCertificate?->certificate_number ?? 'an earlier certificate')
                .'. Deducting it twice takes money the subcontractor has already lost once.'
            );
        }

        if ($charge->contract_id !== $certificate->contract_id) {
            throw new InvalidArgumentException(
                "{$charge->reference} belongs to a different subcontract. A back-charge is recovered from the "
                .'subcontractor who caused it, and putting it on somebody else\'s certificate deducts from the wrong party.'
            );
        }

        if (! $certificate->isDraft()) {
            throw new InvalidArgumentException(
                "{$certificate->certificate_number} is issued. This back-charge belongs on the next certificate."
            );
        }

        $amount = $charge->recoverableAmount();

        if ($amount <= 0.0) {
            throw new InvalidArgumentException("{$charge->reference} has nothing to recover.");
        }

        return TenantTransaction::run(function () use ($charge, $certificate, $amount): CertificateDeduction {
            /*
             * Negative, by §10.3's convention stated once: a negative amount reduces the payment. Through
             * `addDeduction()` rather than creating the row here, so the draft-only rule, the automatic-kind refusal
             * and the recompute all stay with the service that owns the certificate.
             */
            $deduction = $this->certification->addDeduction($certificate, [
                'kind' => CertificateDeduction::KIND_BACK_CHARGE,
                'description' => $charge->reference.' — '.$charge->description,
                'amount' => -1 * $amount,
                'source_type' => $charge::class,
                'source_id' => $charge->getKey(),
            ]);

            $charge->update([
                'status' => BackCharge::STATUS_APPLIED,
                'applied_certificate_id' => $certificate->getKey(),
                'applied_by' => auth()->id(),
                'applied_on' => now()->toDateString(),
            ]);

            return $deduction;
        });
    }

    /**
     * Take the charge back off a certificate.
     *
     * The deduction row goes and the charge returns to the state it was in before — `agreed` where it was settled,
     * otherwise `notified`. Not to draft: notice was served and cannot be unserved, and a charge sent back to draft
     * would be re-notified with a later date, moving it inside a contractual window it had already left.
     */
    public function unapply(BackCharge $charge, ?string $reason = null): BackCharge
    {
        if (! $charge->isApplied()) {
            throw new InvalidArgumentException("{$charge->reference} is not applied to a certificate.");
        }

        $certificate = $charge->appliedCertificate;

        if ($certificate && ! $certificate->isDraft()) {
            throw new InvalidArgumentException(
                "{$certificate->certificate_number} is issued. Its figures are frozen — the way to reverse this "
                .'back-charge is a credit on the next certificate, or voiding that one.'
            );
        }

        return TenantTransaction::run(function () use ($charge, $certificate, $reason): BackCharge {
            $certificate?->deductions()
                ->where('kind', CertificateDeduction::KIND_BACK_CHARGE)
                ->where('source_type', ModuleMap::alias($charge::class))
                ->where('source_id', $charge->getKey())
                ->delete();

            $charge->update([
                'status' => $charge->agreed_on ? BackCharge::STATUS_AGREED : BackCharge::STATUS_NOTIFIED,
                'applied_certificate_id' => null,
                'applied_by' => null,
                'applied_on' => null,
                'notes' => $reason
                    ? trim(($charge->notes ? $charge->notes."\n" : '').'Withdrawn from certificate: '.$reason)
                    : $charge->notes,
            ]);

            if ($certificate) {
                $this->certification->recompute($certificate->refresh());
            }

            return $charge->refresh();
        });
    }

    /**
     * Drop the charge, with a reason.
     *
     * Mandatory for the reason voiding a certificate needs one: somebody outside this company has been told about this
     * charge, and "withdrawn" with no explanation is the row that gets asked about at final account.
     */
    public function withdraw(BackCharge $charge, string $reason): BackCharge
    {
        if ($charge->isApplied()) {
            throw new InvalidArgumentException(
                "{$charge->reference} has been deducted. Take it off the certificate first, so the payment and the "
                .'register move together.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Withdrawing a back-charge needs a reason. The subcontractor was told about it, and this is the row '
                .'that explains why the account does not add up to the notices.'
            );
        }

        $charge->update([
            'status' => BackCharge::STATUS_WITHDRAWN,
            'withdrawn_at' => now(),
            'withdrawn_by' => auth()->id(),
            'withdrawal_reason' => $reason,
        ]);

        return $charge->refresh();
    }

    /**
     * **What a certificate could take, offered rather than applied.**
     *
     * §16.5's precedent, which §12 inherits: the charge proposes and a human confirms. Returning a list is the whole
     * mechanism — nothing in `recompute()` reads this, so a certificate never grows a deduction nobody decided on.
     *
     * @return Collection<int, BackCharge>
     */
    public function offerFor(PaymentCertificate $certificate): Collection
    {
        if (! $certificate->isDraft()) {
            return collect();
        }

        return BackCharge::query()
            ->where('contract_id', $certificate->contract_id)
            ->awaitingApplication()
            ->orderBy('notified_on')
            ->get();
    }

    /**
     * **The exposure: priced, incurred, and nobody told.**
     *
     * §12's sentence as a number. Nothing in this list may be deducted, so every row is money the company has spent on
     * somebody else's obligation and cannot yet recover — and until it is a query, nobody knows the total.
     *
     * @return Collection<int, BackCharge>
     */
    public function unnotified(?Contract $contract = null): Collection
    {
        return BackCharge::query()
            ->unnotified()
            ->when($contract, fn ($q) => $q->where('contract_id', $contract->getKey()))
            ->with(['contract', 'job'])
            ->orderBy('incurred_on')
            ->get();
    }

    /** What the un-notified list is worth, which is what makes it a figure somebody can be asked about. */
    public function unnotifiedTotal(?Contract $contract = null): float
    {
        return round($this->unnotified($contract)->sum(fn (BackCharge $c): float => (float) $c->total_amount), 2);
    }

    /**
     * Notified or settled and not yet recovered — the other half of the exposure.
     *
     * Different from the un-notified list and worth reporting separately: this money is recoverable and simply has not
     * been taken, which is a person's oversight rather than a contractual bar.
     */
    public function awaitingApplicationTotal(?Contract $contract = null): float
    {
        return round(
            BackCharge::query()
                ->awaitingApplication()
                ->when($contract, fn ($q) => $q->where('contract_id', $contract->getKey()))
                ->get()
                ->sum(fn (BackCharge $c): float => $c->recoverableAmount()),
            2,
        );
    }
}
