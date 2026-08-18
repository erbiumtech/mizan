<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\GoodsReceiptLine;
use App\Modules\ConstructionCosting\Models\InvoiceAllocation;
use App\Modules\ConstructionCosting\Support\MatchTolerances;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Ordered against received against invoiced — `docs/construction-management-plan.md` §5.
 *
 * **Computed, not stored.** Every figure here is read from the documents each time it is asked for: the order line, the
 * goods receipt lines against it, the invoice allocations against it. A stored match status is a second answer to a
 * question the documents already answer, and the first thing that happens is a status that stops agreeing with them.
 *
 * **The one exception is the acceptance**, because that is a human decision: `variance_accepted_at`, `_by` and
 * `variance_reason` are stored, and "a decision with no record is not a control". The *status* is still derived —
 * an acceptance turns whatever the figures say into `accepted` rather than replacing it.
 *
 * **Which two of the three legs can actually be judged, and why the third is context.** §5 asks for "ordered against
 * received against invoiced, per commitment line", and all three are on the report. But the variances are the two the
 * documents can settle:
 *
 *  - **invoiced against received** — billed for forty tonnes when thirty-six arrived is the classic catch, and it is
 *    what a three-way match exists for;
 *  - **invoiced value against received value** — the buyer agreed a rate and the supplier billed another.
 *
 * **Ordered against received is not a variance while the order is open**, and the first version of this class had it
 * as one — which made every undelivered order read as a total short delivery, and every staged delivery as a partial
 * one. The report would have been wrong on nearly every line, which is the state that teaches people to ignore it.
 * The deeper reason is that the two cases are indistinguishable: thirty-six tonnes against forty is a short delivery
 * *or* the first of two loads, and nothing in the documents says which. What settles it is somebody closing the order
 * — which §5 already makes an act with an author and a reason — and until then the four tonnes are open commitment,
 * reported by the register that owns that figure rather than twice.
 *
 * Two things this deliberately does **not** do.
 *
 * It does not guess an invoice quantity. Most supplier invoices state money and not tonnes, so where the allocation
 * carries no quantity the quantity variance is **null**, not zero — §14's lesson about schedule variance applies
 * identically here: a zero that means "unknown" reads as "agrees", which is the reassuring wrong answer.
 *
 * It does not block anything. §5 puts the control at *acceptance*, and blocking payment on a match variance is how a
 * site ends up with a supplier refusing the next delivery over 4,000 nobody could authorise.
 */
class ThreeWayMatch
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_QUANTITY_VARIANCE = 'quantity_variance';

    public const STATUS_PRICE_VARIANCE = 'price_variance';

    /** Both at once, which is the ordinary case when a supplier substitutes a product. */
    public const STATUS_BOTH_VARIANCE = 'quantity_and_price_variance';

    /** Nothing has happened yet: ordered, not delivered, not invoiced. */
    public const STATUS_AWAITING = 'awaiting_delivery';

    /**
     * The match for one order line.
     *
     * @return array{
     *     line: CommitmentLine,
     *     ordered_quantity: float|null,
     *     received_quantity: float,
     *     invoiced_quantity: float|null,
     *     ordered_amount: float,
     *     received_amount: float,
     *     invoiced_amount: float,
     *     quantity_variance: float|null,
     *     price_variance: float,
     *     within_tolerance: bool,
     *     status: string,
     *     accepted_at: \Illuminate\Support\Carbon|null,
     *     reason: string|null,
     *     note: string|null,
     * }
     */
    public function forLine(CommitmentLine $line): array
    {
        $received = GoodsReceiptLine::query()
            ->where('commitment_line_id', $line->getKey())
            ->whereHas('receipt', fn ($query) => $query->posted())
            ->get();

        $allocations = InvoiceAllocation::query()
            ->where('commitment_line_id', $line->getKey())
            ->get();

        $orderedQuantity = $line->quantity === null ? null : (float) $line->quantity;
        $receivedQuantity = round((float) $received->sum('quantity'), 4);
        $orderedAmount = (float) $line->amount;
        $receivedAmount = round((float) $received->sum('amount'), 2);
        $invoicedAmount = round((float) $allocations->sum('amount'), 2);

        /*
         * **Null rather than zero where the invoice never stated a quantity.** Most supplier invoices state money, not
         * tonnes; treating that silence as zero would report every one of them as a total short-delivery, and a report
         * where everything is wrong is a report nobody reads.
         */
        $invoicedQuantity = $allocations->whereNotNull('quantity')->isEmpty()
            ? null
            : round((float) $allocations->sum('quantity'), 4);

        /*
         * Quantity: **what was billed against what arrived**, which is the leg the documents can settle. Null where
         * the invoice stated no quantity — see the class docblock on why ordered-against-received is context here
         * rather than a variance.
         */
        $quantityVariance = $invoicedQuantity === null
            ? null
            : round($invoicedQuantity - $receivedQuantity, 4);

        /*
         * Price: what was invoiced against what the goods that arrived were ordered at.
         *
         * Against the **received** value rather than the ordered one, deliberately. An invoice for half an order is
         * not a price variance, it is a part invoice; comparing it to the whole order would flag every staged delivery
         * on every job. Where nothing has been received, the ordered value stands in.
         */
        $priceBase = $received->isNotEmpty() ? $receivedAmount : $orderedAmount;
        $priceVariance = $invoicedAmount == 0.0 ? 0.0 : round($invoicedAmount - $priceBase, 2);

        $quantityOut = $quantityVariance !== null
            && $quantityVariance != 0.0
            && ! MatchTolerances::withinTolerance(
                // In money, so the absolute floor means something: a tolerance in tonnes cannot be compared with one
                // in litres, and a percentage alone flags trivial sums on small orders.
                $quantityVariance * (float) ($line->rate ?? 0),
                $orderedAmount,
                MatchTolerances::quantityPercent(),
            );

        $priceOut = $priceVariance != 0.0
            && ! MatchTolerances::withinTolerance($priceVariance, $priceBase, MatchTolerances::pricePercent());

        /*
         * **Nothing invoiced means nothing to judge**, and that branch has to come before the variance ones: an order
         * that has been delivered but not yet billed is awaiting an invoice, not disagreeing with one.
         */
        $status = match (true) {
            $line->variance_accepted_at !== null => self::STATUS_ACCEPTED,
            $invoicedAmount == 0.0 => self::STATUS_AWAITING,
            $quantityOut && $priceOut => self::STATUS_BOTH_VARIANCE,
            $quantityOut => self::STATUS_QUANTITY_VARIANCE,
            $priceOut => self::STATUS_PRICE_VARIANCE,
            default => self::STATUS_MATCHED,
        };

        return [
            'line' => $line,
            'ordered_quantity' => $orderedQuantity,
            'received_quantity' => $receivedQuantity,
            'invoiced_quantity' => $invoicedQuantity,
            'ordered_amount' => round($orderedAmount, 2),
            'received_amount' => $receivedAmount,
            'invoiced_amount' => $invoicedAmount,
            'quantity_variance' => $quantityVariance,
            'price_variance' => $priceVariance,
            // An uninvoiced line is inside tolerance by definition: there is no second document to disagree with.
            'within_tolerance' => $invoicedAmount == 0.0 || (! $quantityOut && ! $priceOut),
            'status' => $status,
            'accepted_at' => $line->variance_accepted_at,
            'reason' => $line->variance_reason,
            // The sentence a screen prints where a figure is missing rather than agreeing — §14's rule, applied here.
            'note' => $this->note($orderedQuantity, $invoicedQuantity, $invoicedAmount, $receivedQuantity, $line->openAmount()),
        ];
    }

    /**
     * Why a figure on the match is absent or needs a sentence, in the words a screen shows.
     *
     * The outstanding note is the one that keeps ordered-against-received honest: the difference is reported, and
     * reported as *outstanding* rather than as a variance, because nothing in the documents says whether it is a short
     * delivery or the first of two loads.
     */
    private function note(?float $orderedQuantity, ?float $invoicedQuantity, float $invoicedAmount, float $receivedQuantity, float $openAmount): ?string
    {
        if ($orderedQuantity === null) {
            return 'Quantity variance unavailable: this line was ordered as a lump sum, so there is no quantity to compare.';
        }

        if ($invoicedAmount != 0.0 && $invoicedQuantity === null) {
            return 'The invoice stated money but no quantity, so only the price is compared.';
        }

        if ($openAmount > 0.0 && $receivedQuantity < $orderedQuantity) {
            $outstanding = rtrim(rtrim(number_format($orderedQuantity - $receivedQuantity, 4, '.', ''), '0'), '.');

            return "{$outstanding} of this order is still outstanding — a short delivery and a staged one look the "
                .'same until the order is closed, so it is open commitment rather than a variance.';
        }

        return null;
    }

    /**
     * Every line of a commitment, matched.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forCommitment(Commitment $commitment): array
    {
        return $commitment->lines->map(fn (CommitmentLine $line): array => $this->forLine($line))->all();
    }

    /**
     * **The variance report**: lines outside tolerance and not yet accepted.
     *
     * The list somebody works, which is the only form a control of this kind can usefully take. Ordered by the size of
     * the money at stake rather than by order number, because the question is never "what does PO-142 say" — it is
     * "what is worth an argument".
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function variances(?Job $job = null): Collection
    {
        $lines = CommitmentLine::query()
            ->with(['commitment', 'job', 'costCode'])
            ->committing()
            ->when($job, fn ($query) => $query->forJobTree($job))
            ->whereNull('variance_accepted_at')
            ->get();

        return $lines
            ->map(fn (CommitmentLine $line): array => $this->forLine($line))
            ->reject(fn (array $match): bool => $match['within_tolerance'])
            ->sortByDesc(fn (array $match): float => abs($match['price_variance'])
                + abs(($match['quantity_variance'] ?? 0) * (float) ($match['line']->rate ?? 0)))
            ->values();
    }

    /**
     * Accept a variance — the one thing this service stores.
     *
     * The reason is mandatory, and not as ceremony: this row is the answer when somebody asks in six months why the
     * job carries 40,000 more than it was ordered at. "Supplier substituted 20mm for 16mm at our request, agreed with
     * the QS on the 14th" is that answer; a tick is not.
     */
    public function accept(CommitmentLine $line, string $reason): CommitmentLine
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Accepting a variance needs a reason. It is the answer when somebody asks in six months why the job '
                .'carries more than it was ordered at, and a decision with no record is not a control.'
            );
        }

        if ($this->forLine($line)['within_tolerance'] && $line->variance_accepted_at === null) {
            throw new InvalidArgumentException(
                'That line is already inside tolerance, so there is nothing to accept. Accepting it anyway would put '
                .'a decision on the record about a difference nobody was asked to make.'
            );
        }

        $line->update([
            'variance_accepted_at' => now(),
            'variance_accepted_by' => auth()->id(),
            'variance_reason' => $reason,
        ]);

        return $line->refresh();
    }

    /**
     * Withdraw an acceptance, which puts the line back on the report.
     *
     * Kept because an acceptance made on the wrong information is a real thing, and the alternative — a decision that
     * can never be revisited — is how somebody ends up creating a second order to work around the first.
     */
    public function withdrawAcceptance(CommitmentLine $line): CommitmentLine
    {
        $line->update([
            'variance_accepted_at' => null,
            'variance_accepted_by' => null,
            'variance_reason' => null,
        ]);

        return $line->refresh();
    }
}
