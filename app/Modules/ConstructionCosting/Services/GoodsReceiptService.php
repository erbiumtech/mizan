<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\CommitmentRelief;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use App\Modules\ConstructionCosting\Models\GoodsReceiptLine;
use App\Support\TenantTransaction;
use InvalidArgumentException;
use RuntimeException;

/**
 * Receiving goods — `docs/construction-management-plan.md` §5, "where cost first touches the job".
 *
 * Posting a receipt does **two** of the three things §5 lists, and refuses the third rather than faking it.
 *
 *  1. **It relieves the order**, through `CommitmentService` so the double-relief rule stays in one place: relief
 *     happens at the earlier of receipt or certificate, and the invoice later relieves only what was never received.
 *  2. **It raises an accrual at order rate**, through `CostLedger` so the closed-period rule, the heading-code refusal
 *     and the cost-type snapshot all still apply. Between delivery and invoice the job has incurred cost no supplier
 *     document yet proves; a report that waited for the invoice would understate every month end.
 *  3. **It does not write a stock movement.** That needs `stock_locations`, which §6 gives to Inventory and Phase 8
 *     delivers. A line destined for a store is **refused with a message naming what is missing** — accepting it would
 *     cost the material as though it had been stocked, and materials-on-site would then be wrong with nothing saying
 *     so. §18.1's rule: the two places where "smaller, never broken" is not enough are the ones where a healthy
 *     figure hides an absence, and this is one of them.
 *
 * **Posting is idempotent.** Each line records the cost entry it raised, so a second post finds the work done rather
 * than doubling the accrual — which is the same discipline variation incorporation keeps.
 */
class GoodsReceiptService
{
    public function __construct(
        private CommitmentService $commitments,
        private CostLedger $ledger,
    ) {}

    /**
     * Open a receipt, optionally against an order.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(?Commitment $commitment = null, array $attributes = []): GoodsReceipt
    {
        return TenantTransaction::run(fn (): GoodsReceipt => GoodsReceipt::create($attributes + [
            'number' => $this->nextNumber(),
            'commitment_id' => $commitment?->getKey(),
            'contact_id' => $commitment?->contact_id,
            'received_on' => now()->toDateString(),
            'received_by' => auth()->id(),
        ]));
    }

    /** `GRN-2026-0142`, read off the trailing digits so a reversed receipt does not hand its number on. */
    public function nextNumber(?int $year = null): string
    {
        $year ??= (int) now()->year;

        $used = GoodsReceipt::query()
            ->where('number', 'like', "GRN-{$year}-%")
            ->pluck('number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return sprintf('GRN-%d-%04d', $year, $used + 1);
    }

    /**
     * Receive against an order line, which seeds everything from it.
     *
     * The job, the code, the description and the **rate** all come from the order rather than being retyped: the
     * accrual has to be at order rate for the three-way match to mean anything, and a rate typed twice is a rate that
     * will differ.
     *
     * Over-delivery is **allowed** rather than refused. It happens — a supplier sends a full pack rather than the
     * ordered part of one — and the honest treatment is to record what arrived and let the order show as
     * over-relieved, which `Commitment::overRelieved()` already answers. Refusing would leave the material on site and
     * uncosted.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addLineFor(GoodsReceipt $receipt, CommitmentLine $orderLine, float $quantity, array $attributes = []): GoodsReceiptLine
    {
        $this->requireDraft($receipt);

        if ($quantity <= 0) {
            throw new InvalidArgumentException('A received line needs a quantity greater than zero.');
        }

        return $receipt->lines()->create($attributes + [
            'commitment_line_id' => $orderLine->getKey(),
            'job_id' => $orderLine->job_id,
            'wbs_node_id' => $orderLine->wbs_node_id,
            'cost_code_id' => $orderLine->cost_code_id,
            'product_id' => $orderLine->product_id,
            'description' => $orderLine->description,
            'quantity' => $quantity,
            'unit_of_measure' => $orderLine->unit_of_measure,
            'unit_rate' => $orderLine->rate,
        ]);
    }

    /**
     * Receive something nobody ordered.
     *
     * A real event, and the reason the order link is nullable: refusing to record it would leave material on site,
     * uncosted, with the only remedy being to invent an order after the fact. It relieves nothing, because there is
     * nothing to relieve — the cost is real either way.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addLine(GoodsReceipt $receipt, Job $job, CostCode $code, array $attributes): GoodsReceiptLine
    {
        $this->requireDraft($receipt);

        if ((float) ($attributes['quantity'] ?? 0) <= 0) {
            throw new InvalidArgumentException('A received line needs a quantity greater than zero.');
        }

        return $receipt->lines()->create($attributes + [
            'job_id' => $job->getKey(),
            'cost_code_id' => $code->getKey(),
        ]);
    }

    /**
     * Post the receipt: relieve the order, raise the accruals.
     *
     * Refuses a store destination while `stock_locations` does not exist, and says so — see the class docblock.
     */
    public function post(GoodsReceipt $receipt): GoodsReceipt
    {
        if ($receipt->isPosted()) {
            throw new InvalidArgumentException("{$receipt->number} was already posted on {$receipt->posted_at}.");
        }

        if ($receipt->status === GoodsReceipt::STATUS_REVERSED) {
            throw new InvalidArgumentException(
                "{$receipt->number} was reversed. Record the delivery again rather than reposting a receipt somebody "
                .'has already backed out.'
            );
        }

        $receipt->load('lines');

        if ($receipt->lines->isEmpty()) {
            throw new InvalidArgumentException("{$receipt->number} has no lines. Nothing was delivered.");
        }

        foreach ($receipt->lines as $line) {
            if ($line->goesToStore()) {
                throw new RuntimeException(
                    "\"{$line->description}\" is destined for a site store, and site stores need a stock location, "
                    .'which this application does not have yet (§6 gives `stock_locations` to Inventory). Receive it '
                    .'as direct to site — it will be costed correctly — and stock it when site stores arrive. It is '
                    .'refused rather than accepted because a receipt costed as though it had been stocked would make '
                    .'materials-on-site wrong with nothing saying so.'
                );
            }
        }

        return TenantTransaction::run(function () use ($receipt): GoodsReceipt {
            foreach ($receipt->lines as $line) {
                // Already posted: a second call finds the work done rather than doubling the accrual.
                if ($line->isPosted()) {
                    continue;
                }

                if ($line->commitmentLine) {
                    // Through the service, so the double-relief rule lives in one place.
                    $this->commitments->relieve(
                        $line->commitmentLine,
                        CommitmentRelief::KIND_RECEIPT,
                        (float) $line->amount,
                        $line,
                        quantity: (float) $line->quantity,
                        on: $receipt->received_on->toDateString(),
                    );
                }

                /*
                 * The accrual, through `CostLedger` so the rules it owns still apply — a closed period puts it in
                 * the earliest open one with its real date kept, a heading code is refused, and the cost type is
                 * snapshotted off the code.
                 *
                 * `accrual` rather than `actual` because no supplier document proves it yet, and §3.5's report keeps
                 * the two columns apart for exactly that reason: mixing them makes CPI move when nothing happened
                 * on site.
                 */
                $entry = $this->ledger->record(
                    $line->job,
                    $line->costCode,
                    [
                        'kind' => CostEntry::KIND_ACCRUAL,
                        'amount' => (float) $line->amount,
                        'quantity' => $line->quantity,
                        'unit_of_measure' => $line->unit_of_measure,
                        'unit_rate' => $line->unit_rate,
                        'incurred_on' => $receipt->received_on->toDateString(),
                        'wbs_node_id' => $line->wbs_node_id,
                        'description' => $line->description,
                        'reference' => $receipt->delivery_note_reference ?: $receipt->number,
                        'source_type' => $line::class,
                        'source_id' => $line->getKey(),
                    ],
                );

                $line->update(['cost_entry_id' => $entry->getKey()]);
            }

            $receipt->update([
                'status' => GoodsReceipt::STATUS_POSTED,
                'posted_at' => now(),
            ]);

            return $receipt->refresh();
        });
    }

    /**
     * Reverse a posted receipt: give the commitment back and reverse the accruals.
     *
     * Not a delete. A posted receipt has moved the committed figure and the cost report, and both need the correction
     * to be a row somebody can read — the same reason `CostLedger` corrects by reversal rather than by editing.
     */
    public function reverse(GoodsReceipt $receipt, string $reason): GoodsReceipt
    {
        if (! $receipt->isPosted()) {
            throw new InvalidArgumentException("{$receipt->number} is {$receipt->status} and has nothing to reverse.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Reversing a receipt needs a reason: it takes cost off a job and puts commitment back on an order, '
                .'and somebody will ask why.'
            );
        }

        $receipt->load('lines.commitmentLine', 'lines.costEntry');

        return TenantTransaction::run(function () use ($receipt, $reason): GoodsReceipt {
            foreach ($receipt->lines as $line) {
                if ($line->commitmentLine) {
                    // A negative relief rather than a deleted row, so "what did we think was committed in March"
                    // stays answerable.
                    $this->commitments->relieve(
                        $line->commitmentLine,
                        CommitmentRelief::KIND_RECEIPT,
                        -1 * (float) $line->amount,
                        $line,
                        reason: $reason,
                        quantity: -1 * (float) $line->quantity,
                    );
                }

                if ($line->costEntry && ! $line->costEntry->isReversed()) {
                    $this->ledger->reverse($line->costEntry, "Reversal of {$receipt->number}: {$reason}");
                }
            }

            $receipt->update([
                'status' => GoodsReceipt::STATUS_REVERSED,
                'reversal_reason' => $reason,
            ]);

            return $receipt->refresh();
        });
    }

    private function requireDraft(GoodsReceipt $receipt): void
    {
        if (! $receipt->isDraft()) {
            throw new InvalidArgumentException(
                "{$receipt->number} is {$receipt->status}. A posted receipt has already relieved the order and "
                .'raised its accruals; record a second delivery rather than editing this one.'
            );
        }
    }
}
