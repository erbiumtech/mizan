<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CommitmentRelief;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\InvoiceAllocation;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\TenantTransaction;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Attributing a supplier invoice to jobs and cost codes — `docs/construction-management-plan.md` §5.
 *
 * **This service exists because of the failure §5 calls the most likely silent one in the module**: a purchase invoice
 * posted with no allocation leaves the general ledger perfectly correct and the job under-costed, so every margin
 * flatters and no report shows an error. The two answers are structural — the *awaiting allocation* queue this service
 * feeds, and §4.2's always-rendered reconciliation section.
 *
 * Three rules live here.
 *
 *  - **An invoice relieves only the unreceived balance.** Delegated to `CommitmentService`, so §5's double-relief rule
 *    stays in one place: the receipt relieved when the goods arrived, and the invoice for those same goods must not
 *    relieve again.
 *  - **The cost entry mirrors the ledger rather than pending for it.** The purchase invoice is what reaches the general
 *    ledger; the job-cost entry is the same money seen from the job's side, which is `gl_treatment = mirrored` and
 *    §4.1's "nothing double-posts" in one column.
 *  - **The receipt's accrual is left alone.** §4.5: accruals auto-reverse at the opening of the next period rather
 *    than being matched off against the eventual invoice, because line-by-line matching is the same heuristic that
 *    fails for commitment relief. The consequence — an accrual and its invoice both standing inside one period — is
 *    named in §4.5 and is why the reversal belongs to period *open*.
 */
class InvoiceAllocationService
{
    public function __construct(
        private CommitmentService $commitments,
        private CostLedger $ledger,
    ) {}

    /** Whether allocation is possible at all: Invoicing owns the invoice, and §18 keeps it guarded. */
    public function isAvailable(): bool
    {
        return modules()->enabled('invoicing');
    }

    /**
     * Allocate part of an invoice to a job and a cost code, and cost it.
     *
     * @param  array<string, mixed>  $attributes  `invoice_line_id`, `wbs_node_id`, `commitment_line_id`, `quantity`,
     *                                            `description` — all optional
     */
    public function allocate(
        Invoice $invoice,
        Job $job,
        CostCode $code,
        float $amount,
        array $attributes = [],
    ): InvoiceAllocation {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'Allocating an invoice to a job needs the Invoicing module, which owns the invoice.'
            );
        }

        if ($invoice->kind !== Invoice::KIND_PURCHASE) {
            throw new InvalidArgumentException(
                "{$invoice->invoice_number} is a {$invoice->kind} invoice. Only a purchase invoice carries cost to "
                .'attribute — what a sales invoice carries is revenue, and §10 certifies that rather than allocating it.'
            );
        }

        if ($amount == 0.0) {
            throw new InvalidArgumentException('An allocation of nothing tells nobody anything.');
        }

        if (! $code->is_leaf) {
            throw new InvalidArgumentException(
                "Cost code {$code->code} is a heading. Allocating to it would double-count in every rolled-up total."
            );
        }

        /*
         * **Over-allocation is refused**, and it is the one refusal in this service that protects a figure rather than
         * a convention: allocating 120 of a 100 invoice puts cost on a job that no supplier ever charged, and the
         * general ledger would not disagree — it never sees the allocation at all.
         */
        $unallocated = $this->unallocated($invoice, $attributes['invoice_line_id'] ?? null);

        if (abs(round($amount, 2)) > abs($unallocated) + 0.001 || ($amount <=> 0) !== ($unallocated <=> 0)) {
            throw new InvalidArgumentException(
                'Allocating '.number_format($amount, 2).' where '.number_format($unallocated, 2)
                .' is unallocated. The job would carry cost the supplier never charged, and the general ledger would '
                .'not disagree — it never sees the allocation.'
            );
        }

        return TenantTransaction::run(function () use ($invoice, $job, $code, $amount, $attributes): InvoiceAllocation {
            $allocation = InvoiceAllocation::create([
                'invoice_id' => $invoice->getKey(),
                'invoice_line_id' => $attributes['invoice_line_id'] ?? null,
                'job_id' => $job->getKey(),
                'wbs_node_id' => $attributes['wbs_node_id'] ?? null,
                'cost_code_id' => $code->getKey(),
                'commitment_line_id' => $attributes['commitment_line_id'] ?? null,
                'amount' => round($amount, 2),
                'quantity' => $attributes['quantity'] ?? null,
                'description' => $attributes['description'] ?? null,
                'allocated_by' => auth()->id(),
            ]);

            /*
             * The cost, through `CostLedger` so its rules apply — a closed period takes it into the open one with the
             * invoice date kept, and the cost type is snapshotted off the code.
             *
             * `mirrored`, not `pending`: the purchase invoice is what reaches the general ledger, and this is the same
             * money seen from the job's side. An entry marked `pending` here would sit on §4's chase-list forever.
             *
             * **And it carries the journal entry the invoice reached**, which §4.2's reconciliation needs. Saying "somebody
             * else posted this" is not enough for a report that has to say *which* GL cost has a job behind it — and
             * §4.2's nastiest named cause is "cost entries pointing at a journal entry that was later reversed or
             * unposted", which is undetectable unless a mirrored entry points at one. Null where the invoice has not
             * been posted yet, which is a state §4.2 reports rather than a state this refuses.
             */
            $entry = $this->ledger->record($job, $code, [
                'kind' => CostEntry::KIND_ACTUAL,
                'gl_treatment' => CostEntry::GL_MIRRORED,
                'journal_entry_id' => $invoice->journal_entry_id,
                'amount' => round($amount, 2),
                'quantity' => $attributes['quantity'] ?? null,
                'incurred_on' => $invoice->invoice_date?->toDateString() ?? now()->toDateString(),
                'wbs_node_id' => $attributes['wbs_node_id'] ?? null,
                'description' => $attributes['description']
                    ?? ($allocation->invoiceLine?->description ?? "Invoice {$invoice->invoice_number}"),
                'reference' => $invoice->invoice_number,
                'source_type' => $allocation::class,
                'source_id' => $allocation->getKey(),
            ]);

            $allocation->update(['cost_entry_id' => $entry->getKey()]);

            /*
             * Relieve the order — **only the part of this invoice that covers goods no delivery relieved**.
             *
             * §5's rule is that relief happens once, at the earlier of receipt or certificate, and the invoice relieves
             * only the unreceived balance. `CommitmentService` enforces the ceiling; the *intent* is worked out here,
             * because only the documents on this side know what has actually been invoiced.
             *
             * The distinction matters and the first version got it wrong: an invoice for the 36 t that arrived was
             * relieving the 4 t that never did, because the clamp only knew there was an unreceived balance left. That
             * closed the order as though the shortfall had been settled, and the shortfall is exactly what the
             * commitment register exists to keep visible.
             */
            if ($line = $allocation->commitmentLine) {
                $invoicedOnLine = (float) InvoiceAllocation::query()
                    ->where('commitment_line_id', $line->getKey())
                    ->sum('amount');

                // What the invoices claim beyond what the deliveries already relieved, less whatever earlier invoices
                // on this line have relieved already.
                $excess = round(
                    min(abs($invoicedOnLine), abs((float) $line->amount))
                    - $line->receivedTotal()
                    - $line->invoicedTotal(),
                    2,
                );

                if ($excess > 0.0) {
                    $this->commitments->relieve(
                        $line,
                        CommitmentRelief::KIND_INVOICE,
                        $excess,
                        $allocation,
                        on: $invoice->invoice_date?->toDateString(),
                    );
                }
            }

            return $allocation->refresh();
        });
    }

    /**
     * Undo an allocation: reverse the cost and give the commitment back.
     *
     * Not a delete of the cost. §3.3's line holds here too — the job carried that cost for however long the mistake
     * stood, and a reversal is what says so. The allocation row itself goes, because unlike a cost entry it is not a
     * statement about a day; it is an attribution, and the attribution was simply wrong.
     */
    public function deallocate(InvoiceAllocation $allocation, ?string $reason = null): void
    {
        TenantTransaction::run(function () use ($allocation, $reason): void {
            if ($allocation->costEntry && ! $allocation->costEntry->isReversed()) {
                $this->ledger->reverse(
                    $allocation->costEntry,
                    $reason ?: "Allocation of invoice {$allocation->invoice?->invoice_number} withdrawn",
                );
            }

            if ($line = $allocation->commitmentLine) {
                /*
                 * Give back **what this allocation actually relieved**, which is not always what it was for: an
                 * invoice covering goods already received relieved nothing, and handing back its full value would
                 * put commitment on the order that was never taken off it.
                 */
                $relieved = (float) CommitmentRelief::query()
                    ->where('commitment_line_id', $line->getKey())
                    ->where('source_type', \App\Support\ModuleMap::alias($allocation::class))
                    ->where('source_id', $allocation->getKey())
                    ->sum('amount');

                if ($relieved != 0.0) {
                    // A negative relief, so the order's history keeps both rows.
                    $this->commitments->relieve(
                        $line,
                        CommitmentRelief::KIND_INVOICE,
                        -1 * $relieved,
                        $allocation,
                        reason: $reason,
                    );
                }
            }

            $allocation->delete();
        });
    }

    /**
     * What is still unallocated on an invoice, or on one of its lines.
     *
     * Computed, and the whole queue reads it. A stored flag would be one more thing to forget, and forgetting it is
     * the failure the queue exists to catch.
     */
    public function unallocated(Invoice $invoice, int|string|null $invoiceLineId = null): float
    {
        if ($invoiceLineId !== null) {
            $line = $invoice->lines->firstWhere('id', (int) $invoiceLineId);

            $allocated = (float) InvoiceAllocation::query()
                ->where('invoice_line_id', $invoiceLineId)
                ->sum('amount');

            return round((float) ($line->line_total ?? 0) - $allocated, 2);
        }

        $allocated = (float) InvoiceAllocation::query()
            ->where('invoice_id', $invoice->getKey())
            ->sum('amount');

        // The subtotal rather than the total: tax is not job cost, and allocating a gross figure would put the
        // supplier's sales tax on the job's margin.
        return round((float) $invoice->subtotal - $allocated, 2);
    }

    public function allocatedTotal(Invoice $invoice): float
    {
        return round((float) InvoiceAllocation::query()->where('invoice_id', $invoice->getKey())->sum('amount'), 2);
    }

    public function isFullyAllocated(Invoice $invoice): bool
    {
        return abs($this->unallocated($invoice)) < 0.01;
    }

    /**
     * **The queue.** Purchase invoices with anything left unallocated.
     *
     * §5 asks for this to be "a screen people work from" rather than a report somebody remembers to run, because the
     * alternative is a general ledger that is right and a job that is under-costed, with nothing anywhere disagreeing.
     *
     * Oldest first: an invoice unallocated for two months is a job that has been reporting a flattering margin for two
     * months.
     *
     * @return Collection<int, Invoice>
     */
    public function awaitingAllocation(): Collection
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        return Invoice::query()
            ->with('lines')
            ->where('kind', Invoice::KIND_PURCHASE)
            ->orderBy('invoice_date')
            ->get()
            ->filter(fn (Invoice $invoice): bool => ! $this->isFullyAllocated($invoice))
            ->values();
    }

    /** What the queue is worth in total, which is what makes it a number somebody can be asked about. */
    public function awaitingTotal(): float
    {
        return round($this->awaitingAllocation()->sum(fn (Invoice $invoice): float => $this->unallocated($invoice)), 2);
    }
}
