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
use App\Modules\Inventory\Models\StockMovement;
use App\Support\TenantTransaction;
use InvalidArgumentException;
use RuntimeException;

/**
 * Receiving goods — `docs/construction-management-plan.md` §5, "where cost first touches the job".
 *
 * Posting a receipt does all **three** things §5 lists, since Phase 8a built `stock_locations`.
 *
 *  1. **It relieves the order**, through `CommitmentService` so the double-relief rule stays in one place: relief
 *     happens at the earlier of receipt or certificate, and the invoice later relieves only what was never received.
 *  2. **It raises an accrual at order rate**, through `CostLedger` so the closed-period rule, the heading-code refusal
 *     and the cost-type snapshot all still apply. Between delivery and invoice the job has incurred cost no supplier
 *     document yet proves; a report that waited for the invoice would understate every month end.
 *  3. **It writes a stock movement — but only where the line says store**, and only when Inventory is licensed, the
 *     line names a product and the job has a location. Phase 5c refused every store line with one message because
 *     `stock_locations` did not exist; now there are three specific refusals, each naming what is missing. It is still
 *     a refusal rather than a quiet fallback to direct, for the reason Phase 5c gave and which has not stopped being
 *     true: a receipt costed as though it had been stocked makes materials-on-site wrong with nothing saying so, and
 *     §18.1's exception is exactly the case where a healthy figure hides an absence.
 *
 * **Direct to site remains the default and touches no stock at all** (§6), which is what keeps this usable by the
 * contractor who buys everything straight to the work face and tracks no stock — "most of them, most of the time".
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
            /*
             * The order's rate, or the rate its lump sum implies.
             *
             * A commitment line may carry an `amount` with no `quantity` and no `rate` — a lump-sum order, which is
             * ordinary. Copying a null rate straight through left the receipt line with `amount` 0, because the line's
             * own saving hook only computes it when a rate is present: **the delivery then cost the job nothing at
             * all.** Phase 8a made it worse by stocking the lot at nil value as well. Deriving the rate keeps a
             * lump-sum order priceable and a part delivery pro-rata.
             */
            'unit_rate' => $orderLine->rate ?? $this->impliedRate($orderLine),
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

        $receipt->load('lines.job');

        foreach ($receipt->lines as $line) {
            if ($line->goesToStore()) {
                $this->guardStoreLine($line);
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
                        // §4.5's first accrual: goods received not invoiced. Named here so §11a's posting service
                        // credits GRNI rather than reporting the entry as owing a credit nobody chose.
                        'gl_purpose' => 'grni',
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

                /*
                 * And into the store, where the line says so — §6's third path, unblocked by Phase 8a's
                 * `stock_locations`.
                 *
                 * A `purchase` movement, because that is what it is: a lot arriving at a location, with
                 * `remaining_quantity` set so the FIFO engine can consume it when the material is issued. No journal
                 * entry is attached — the cost has already reached the job as the accrual above, and the GL side of
                 * goods-received-not-invoiced is §11's posting. Two postings for one delivery is the failure this
                 * division of labour exists to avoid: "the module owns the document, Inventory owns the movement".
                 */
                if ($line->goesToStore()) {
                    $this->stockLine(
                        $line,
                        $receipt->received_on->toDateString(),
                        $receipt->delivery_note_reference ?: $receipt->number,
                    );
                }
            }

            $receipt->update([
                'status' => GoodsReceipt::STATUS_POSTED,
                'posted_at' => now(),
            ]);

            return $receipt->refresh();
        });
    }

    /**
     * What a store-destined line needs before it can be stocked, each absence named.
     *
     * Phase 5c refused every store line with one message, because `stock_locations` did not exist. Phase 8a built it, so
     * the refusal is now three specific ones — and each names the fix, because "receive it as direct to site instead" is
     * useful advice only when somebody knows which of three things is missing.
     *
     * Still a refusal rather than a silent fallback to direct: a receipt costed as though it had been stocked would make
     * materials-on-site wrong with nothing saying so, which is the sentence Phase 5c wrote and which has not stopped
     * being true.
     */
    private function guardStoreLine(GoodsReceiptLine $line): void
    {
        if (! modules()->enabled('inventory')) {
            throw new RuntimeException(
                "\"{$line->description}\" is destined for a site store, and a store keeps stock — which needs the "
                .'Inventory module. Receive it as direct to site instead: it will be costed correctly, and it is the '
                .'path most contractors use for everything (§6).'
            );
        }

        if ($line->product_id === null) {
            throw new RuntimeException(
                "\"{$line->description}\" is destined for a site store but names no product, and stock is kept per "
                .'product. Either pick the product, or receive the line as direct to site — a store movement with '
                .'nothing to move would leave the quantity nowhere.'
            );
        }

        if ($this->lotCost($line) <= 0.0) {
            throw new RuntimeException(
                "\"{$line->description}\" is destined for a site store but has no value — no rate and no amount to "
                .'imply one. Stocking it would put material on hand at nil cost, so materials on site would read as '
                .'nothing while the store was full, which is the worst of the two wrong answers. Price the line, or '
                .'receive it as direct to site.'
            );
        }

        if ($line->job?->stock_location_id === null) {
            throw new RuntimeException(
                "\"{$line->description}\" is destined for a site store, but "
                .($line->job?->code ?? 'that job')
                .' has no store set. Give the job a stock location first — otherwise the material is on hand '
                .'somewhere nobody can name, which reads as a healthy total at no location at all.'
            );
        }
    }

    /**
     * The stock side of a store receipt: one lot, at the job's own location.
     *
     * Written directly rather than through `InventoryService::purchase()`, and that is the one judgement here worth
     * defending. That method posts a balanced journal entry — debit Inventory, credit Cash — which is right for stock
     * bought over the counter and wrong twice over here: the money is owed to a supplier rather than paid, and the cost
     * has already reached the job as the accrual beside this call. §6 draws the line in the same place: "the module owns
     * the document, Inventory owns the movement", which is what `InvoiceService::recordMovement()` does today.
     */
    /**
     * The rate a lump-sum order line implies, where it states no rate of its own.
     *
     * Null when the order line has no quantity either — there is then no basis at all, and inventing one would put a
     * number on a delivery nobody priced. `guardStoreLine()` refuses to stock that; a direct-to-site line records the
     * quantity with no value, which is what it has always done.
     */
    /**
     * What one unit of this line costs, for the lot.
     *
     * The rate where there is one, and what the line's own amount implies where there is not — a line can be entered
     * with a value and no rate, and a lot valued at nothing is materials on site reading as zero while the store is
     * full.
     */
    private function lotCost(GoodsReceiptLine $line): float
    {
        if ((float) ($line->unit_rate ?? 0) > 0.0) {
            return (float) $line->unit_rate;
        }

        $quantity = (float) ($line->quantity ?? 0);

        return $quantity > 0.0 ? round((float) $line->amount / $quantity, 4) : 0.0;
    }

    private function impliedRate(CommitmentLine $orderLine): ?float
    {
        $quantity = (float) ($orderLine->quantity ?? 0);

        return $quantity > 0.0 ? round((float) $orderLine->amount / $quantity, 4) : null;
    }

    private function stockLine(GoodsReceiptLine $line, string $on, ?string $receiptReference): void
    {
        StockMovement::create([
            'product_id' => $line->product_id,
            'stock_location_id' => $line->job->stock_location_id,
            'type' => 'purchase',
            'quantity' => (float) $line->quantity,
            'unit_cost' => $this->lotCost($line),
            // The whole quantity is unconsumed on arrival, which is what lets an issue take it at FIFO cost later.
            'remaining_quantity' => (float) $line->quantity,
            'movement_date' => $on,
            'reference' => $receiptReference,
            'source_type' => $line::class,
            'source_id' => $line->getKey(),
        ]);
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
