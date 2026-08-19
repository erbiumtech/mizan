<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\GoodsReceiptLine;
use App\Modules\ConstructionCosting\Models\MaterialIssue;
use App\Modules\ConstructionCosting\Models\MaterialIssueLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Support\ModuleMap;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Material out of a site store — `docs/construction-management-plan.md` §6.
 *
 * **Posting an issue adds no cost, and every other rule here follows from that one.** §6 makes materials on site
 * "delivered, costed, not yet consumed", so the *receipt* is what costs the material — a store receipt writes its
 * accrual against the code it arrived on. An issue therefore **reclassifies**: it takes the FIFO value out of the code
 * the material was received at and puts it on the code it was used on, as a pair of `reclass` entries that sum to zero.
 * Booking new cost here would charge every stocked delivery twice, and both figures would look like material cost on
 * the same job — nothing would disagree.
 *
 * The pieces that makes possible:
 *
 *  - **`InventoryValuationService::consume()` reports the lots it took**, and a lot names the goods-receipt line it
 *    arrived on, which names the cost code. That chain is the whole mechanism; without it an issue would have to guess
 *    where the cost currently sits.
 *  - **A reclass across cost types is refused.** Material received as material cannot be issued to a labour code — it
 *    is a real business rule, and it also keeps the pair inside one GL account so "sums to zero" is true in the general
 *    ledger as well as in the job. That is what lets the pair be `memo`: it genuinely never needs to post.
 *  - **Where the code is unchanged, nothing is written at all.** A pair that nets to zero on one code is two rows of
 *    noise on a cost report that people have to read.
 *  - **Wastage is its own `waste` movement**, so what was wasted is a query on a type rather than a column somebody has
 *    to remember to subtract. Both halves are cost the company paid for and both stay on the job.
 *
 * **Guarded on Inventory** (§18.1), and there is nothing to degrade to: an issue *is* a stock movement, so without the
 * module the whole document is refused in one sentence and §6's direct-to-site path is the only one — "most of them,
 * most of the time".
 */
class MaterialIssueService
{
    public function __construct(private readonly InventoryValuationService $valuation, private readonly CostLedger $ledger) {}

    public function isAvailable(): bool
    {
        return modules()->enabled('inventory');
    }

    /**
     * Open a docket against a store.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(StockLocation $store, array $attributes = []): MaterialIssue
    {
        $this->requireInventory();

        return TenantTransaction::run(fn (): MaterialIssue => MaterialIssue::create(array_merge($attributes, [
            'stock_location_id' => $store->getKey(),
            'number' => $attributes['number'] ?? $this->nextNumber(),
            'issued_on' => Carbon::parse($attributes['issued_on'] ?? now())->toDateString(),
        ])));
    }

    /**
     * The next number in the year's series.
     *
     * Read off the trailing digits rather than by counting rows, so a deleted draft does not make the next docket reuse
     * a number the storeman has already written on paper — the same reasoning `CommitmentService::nextNumber()` records.
     */
    public function nextNumber(?int $year = null): string
    {
        $year ??= (int) now()->year;

        $used = MaterialIssue::query()
            ->where('number', 'like', "MI-{$year}-%")
            ->pluck('number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return sprintf('MI-%d-%04d', $year, $used + 1);
    }

    /**
     * Add a line: a product, a quantity, and where the material went.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addLine(
        MaterialIssue $issue,
        Job $job,
        CostCode $code,
        Product $product,
        array $attributes = [],
    ): MaterialIssueLine {
        if (! $issue->isDraft()) {
            throw new InvalidArgumentException(
                "{$issue->number} is {$issue->status}. A further issue is a new docket — this one has already moved "
                .'stock, and a line added afterwards would move stock nobody signed for.'
            );
        }

        $quantity = (float) ($attributes['quantity'] ?? 0);
        $wastage = (float) ($attributes['wastage_quantity'] ?? 0);

        $this->guardQuantities($quantity, $wastage);
        $this->guardCode($code);

        if (! $code->is_leaf) {
            throw new InvalidArgumentException("Cost code {$code->code} is a heading and cannot take material.");
        }

        return $issue->lines()->create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'cost_code_id' => $code->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
            'wastage_quantity' => $wastage,
        ]));
    }

    /**
     * Post the docket: take the stock, value it, and reclassify the cost.
     *
     * Two movements per line where there is wastage — `issue` for the usable part and `waste` for the rest — and the
     * consumption happens in that order so FIFO stays FIFO across the split.
     */
    public function post(MaterialIssue $issue): MaterialIssue
    {
        $this->requireInventory();

        if ($issue->isPosted()) {
            throw new InvalidArgumentException("{$issue->number} was already posted on {$issue->posted_at}.");
        }

        if ($issue->isReversed()) {
            throw new InvalidArgumentException(
                "{$issue->number} was reversed. Write a new docket rather than reposting one somebody backed out."
            );
        }

        $issue->load(['lines.product', 'lines.job', 'lines.costCode', 'store']);

        if ($issue->lines->isEmpty()) {
            throw new InvalidArgumentException("{$issue->number} has no lines. Nothing left the store.");
        }

        return TenantTransaction::run(function () use ($issue): MaterialIssue {
            foreach ($issue->lines as $line) {
                $this->postLine($issue, $line);
            }

            $issue->update([
                'status' => MaterialIssue::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            return $issue->refresh();
        });
    }

    private function postLine(MaterialIssue $issue, MaterialIssueLine $line): void
    {
        $store = $issue->stock_location_id;
        $on = $issue->issued_on->toDateString();

        $usable = $line->usableQuantity();
        $wasted = (float) $line->wastage_quantity;

        // Usable first, then the wastage, so the FIFO order across the split is the order the material actually left.
        $taken = $usable > 0.0 ? $this->valuation->consume($line->product, $usable, $store) : [];
        $wastedLots = $wasted > 0.0 ? $this->valuation->consume($line->product, $wasted, $store) : [];

        $usableCost = round(array_sum(array_column($taken, 'cost')), 2);
        $wastedCost = round(array_sum(array_column($wastedLots, 'cost')), 2);
        $total = round($usableCost + $wastedCost, 2);

        $line->update([
            'unit_cost' => (float) $line->quantity > 0.0 ? round($total / (float) $line->quantity, 4) : null,
            'amount' => $total,
        ]);

        if ($usable > 0.0) {
            $this->movement($line, StockMovement::TYPE_ISSUE, -$usable, $usableCost, $store, $on, $issue->reference ?: $issue->number);
        }

        if ($wasted > 0.0) {
            $this->movement($line, StockMovement::TYPE_WASTE, -$wasted, $wastedCost, $store, $on, $issue->reference ?: $issue->number);
        }

        // The whole quantity is reclassified, wastage included: wasted material was bought and paid for, and it was
        // wasted *on this activity*, so the code it was used on is where the loss belongs.
        $this->reclassify($line, [...$taken, ...$wastedLots], $on);
    }

    /** One stock movement, at the store, pointing back at the line that caused it. */
    private function movement(
        MaterialIssueLine $line,
        string $type,
        float $signedQuantity,
        float $cost,
        int|string|null $store,
        string $on,
        ?string $reference,
    ): StockMovement {
        return StockMovement::create([
            'product_id' => $line->product_id,
            'stock_location_id' => $store,
            'type' => $type,
            'quantity' => $signedQuantity,
            // `total_cost` is where the valuation engine records COGS on a negative movement, and an issue is the same
            // shape of event: stock leaving at the cost the lots held.
            'total_cost' => $cost,
            'movement_date' => $on,
            'reference' => $reference,
            'source_type' => $line::class,
            'source_id' => $line->getKey(),
        ]);
    }

    /**
     * Move the cost from where the material was received to where it was used.
     *
     * **A pair of `reclass` entries that sum to zero, and `memo` because it never needs to post.** The refusal below is
     * what makes that second claim true: keeping the reclass inside one cost type keeps it inside one job-cost account,
     * so the general ledger is genuinely unaffected. A reclass across types would move money between accounts and
     * `memo` would then be a lie.
     *
     * Lots with no traceable receipt — stock bought through Inventory's own purchase path — are left alone: there is no
     * code to move the cost *from*, and inventing one would put a figure on a code nobody booked to.
     *
     * @param  array<int, array{lot: ?StockMovement, quantity: float, cost: float}>  $taken
     */
    private function reclassify(MaterialIssueLine $line, array $taken, string $on): void
    {
        foreach ($this->byReceivedCode($taken) as $received) {
            /** @var CostCode $from */
            $from = $received['code'];
            $cost = round($received['cost'], 2);

            if ($cost === 0.0 || $from->getKey() === $line->cost_code_id) {
                // Same code, or nothing to move. A pair that nets to zero on one code is two rows of noise.
                continue;
            }

            if ($from->cost_type !== $line->costCode->cost_type) {
                throw new RuntimeException(
                    "{$line->product->sku} was received against {$from->code}, a {$from->cost_type} code, and is being "
                    ."issued to {$line->costCode->code}, a {$line->costCode->cost_type} code. Moving cost between "
                    .'types would move it between ledger accounts, which an issue is not allowed to do — issue it to a '
                    .$from->cost_type.' code, or correct the code the delivery was received against.'
                );
            }

            $this->reclassEntry($line, $from, -$cost, $on, "Issued out of {$from->code}");
            $this->reclassEntry($line, $line->costCode, $cost, $on, "Issued to {$line->costCode->code}");
        }
    }

    /**
     * The FIFO cost of this issue, grouped by the cost code each lot was received against.
     *
     * A lot's `source` is the goods-receipt line it arrived on, and that line carries the code. Read by id rather than
     * through the morph relation, because the alias in the column is what `ModuleMap` wrote and resolving it back to a
     * class per lot is a query per lot for a fact one query answers.
     *
     * @param  array<int, array{lot: ?StockMovement, quantity: float, cost: float}>  $taken
     * @return array<int, array{code: CostCode, cost: float}>
     */
    private function byReceivedCode(array $taken): array
    {
        $receiptLineIds = [];

        foreach ($taken as $row) {
            if ($row['lot'] !== null && $row['lot']->source_id !== null) {
                $receiptLineIds[] = $row['lot']->source_id;
            }
        }

        $codesByReceiptLine = GoodsReceiptLine::query()
            ->whereIn('id', array_unique($receiptLineIds))
            ->with('costCode')
            ->get()
            ->mapWithKeys(fn (GoodsReceiptLine $receiptLine): array => [
                $receiptLine->getKey() => $receiptLine->costCode,
            ]);

        $grouped = [];

        foreach ($taken as $row) {
            $code = $row['lot']?->source_id === null
                ? null
                : $codesByReceiptLine->get($row['lot']->source_id);

            if ($code === null) {
                // Stock with no traceable receipt: nothing to move the cost from.
                continue;
            }

            $grouped[$code->getKey()] ??= ['code' => $code, 'cost' => 0.0];
            $grouped[$code->getKey()]['cost'] = round($grouped[$code->getKey()]['cost'] + $row['cost'], 2);
        }

        return array_values($grouped);
    }

    private function reclassEntry(MaterialIssueLine $line, CostCode $code, float $amount, string $on, string $description): CostEntry
    {
        return $this->ledger->record($line->job, $code, [
            'kind' => CostEntry::KIND_RECLASS,
            // Never reaches the general ledger, and does not need to: the pair sums to zero inside one account,
            // which the cost-type refusal above is what guarantees.
            'gl_treatment' => CostEntry::GL_MEMO,
            'amount' => $amount,
            'incurred_on' => $on,
            'wbs_node_id' => $line->wbs_node_id,
            'description' => $description.' — '.$line->displayName(),
            'source_type' => $line::class,
            'source_id' => $line->getKey(),
        ]);
    }

    /**
     * Material back into the store, against the line it went out on.
     *
     * §6 puts `returned_quantity` on the line rather than making a return its own document, and the reason is the paper:
     * the docket the material left on is still in the file, and a second numbering series for the reversal of a docket
     * somebody is holding is a series nobody reconciles.
     *
     * Returned at the cost it left at — the line's own blended rate. Revaluing it at today's FIFO would make a return
     * a way of changing the value of stock without buying anything.
     */
    public function recordReturn(MaterialIssueLine $line, float $quantity, ?string $reason = null): StockMovement
    {
        $this->requireInventory();

        $line->loadMissing(['materialIssue', 'product', 'job', 'costCode']);

        if (! $line->materialIssue->isPosted()) {
            throw new InvalidArgumentException(
                'Material can only come back off a posted docket. A draft has not left the store yet — correct the '
                .'quantity on the line instead.'
            );
        }

        if ($quantity <= 0.0) {
            throw new InvalidArgumentException('A return needs a quantity greater than zero.');
        }

        if ($quantity > $line->outstandingQuantity() + 0.0001) {
            throw new InvalidArgumentException(
                'That is more than is still out on this line — '.$line->outstandingQuantity().' of '
                .$line->product->sku.'. More material back than went out is either the wrong line, or a delivery '
                .'somebody is recording as a return.'
            );
        }

        $cost = round($quantity * (float) $line->unit_cost, 2);
        $on = now()->toDateString();

        return TenantTransaction::run(function () use ($line, $quantity, $cost, $on, $reason): StockMovement {
            $movement = StockMovement::create([
                'product_id' => $line->product_id,
                'stock_location_id' => $line->materialIssue->stock_location_id,
                'type' => StockMovement::TYPE_RETURN,
                'quantity' => $quantity,
                'unit_cost' => (float) $line->unit_cost,
                // Unconsumed again, so the next issue can take it — at the rate it came back at, which is the rate it
                // left at.
                'remaining_quantity' => $quantity,
                'movement_date' => $on,
                'reference' => $reason ?: $line->materialIssue->number,
                'source_type' => $line::class,
                'source_id' => $line->getKey(),
            ]);

            // And the reclass unwinds pro-rata: the material is no longer used on the code it was issued to.
            $this->unwindReclass($line, $cost, $on, $reason);

            $line->update([
                'returned_quantity' => round((float) $line->returned_quantity + $quantity, 4),
            ]);

            return $movement;
        });
    }

    /**
     * Reverse a posted docket, with a reason.
     *
     * Counter movements rather than deleted rows, and the reclass pairs reversed — the same discipline `CostLedger`
     * keeps, so "what did we think the store held in March" stays answerable.
     */
    public function reverse(MaterialIssue $issue, string $reason): MaterialIssue
    {
        if (! $issue->isPosted()) {
            throw new InvalidArgumentException("{$issue->number} is {$issue->status} and has nothing to reverse.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Reversing a docket needs a reason: it puts material back on the store and moves cost between codes, '
                .'and somebody signed the paper.'
            );
        }

        $issue->load(['lines.product', 'lines.job', 'lines.costCode']);

        return TenantTransaction::run(function () use ($issue, $reason): MaterialIssue {
            foreach ($issue->lines as $line) {
                if ((float) $line->amount === 0.0) {
                    continue;
                }

                StockMovement::create([
                    'product_id' => $line->product_id,
                    'stock_location_id' => $issue->stock_location_id,
                    'type' => StockMovement::TYPE_RETURN,
                    'quantity' => (float) $line->quantity - (float) $line->returned_quantity,
                    'unit_cost' => (float) $line->unit_cost,
                    'remaining_quantity' => (float) $line->quantity - (float) $line->returned_quantity,
                    'movement_date' => now()->toDateString(),
                    'reference' => $reason,
                    'source_type' => $line::class,
                    'source_id' => $line->getKey(),
                ]);

                $outstanding = round(
                    (float) $line->amount - ((float) $line->returned_quantity * (float) $line->unit_cost),
                    2,
                );

                $this->unwindReclass($line, $outstanding, now()->toDateString(), $reason);
            }

            $issue->update([
                'status' => MaterialIssue::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
            ]);

            return $issue->refresh();
        });
    }

    /**
     * Put a share of the reclassified cost back where it came from.
     *
     * Read from the entries this line already wrote rather than by re-deriving the lots, which have been consumed: the
     * positive entries say which code the cost went to and the negative ones say where it came from, and unwinding is
     * the same pair with the signs swapped, scaled by the share coming back.
     */
    private function unwindReclass(MaterialIssueLine $line, float $cost, string $on, ?string $reason): void
    {
        if ($cost <= 0.0 || (float) $line->amount <= 0.0) {
            return;
        }

        $share = min(1.0, $cost / (float) $line->amount);

        $written = CostEntry::query()
            ->where('source_type', ModuleMap::alias($line::class))
            ->where('source_id', $line->getKey())
            ->where('kind', CostEntry::KIND_RECLASS)
            ->where('amount', '<', 0)
            ->get();

        foreach ($written as $entry) {
            $portion = round(abs((float) $entry->amount) * $share, 2);

            if ($portion === 0.0) {
                continue;
            }

            // Back onto the code it was taken from, and off the code it was issued to.
            $this->reclassEntry(
                $line,
                CostCode::query()->findOrFail($entry->cost_code_id),
                $portion,
                $on,
                'Returned to '.($reason ?: 'store'),
            );
            $this->reclassEntry(
                $line,
                $line->costCode,
                -$portion,
                $on,
                'Returned from '.$line->costCode->code,
            );
        }
    }

    // ------------------------------------------------------------------ guards

    private function requireInventory(): void
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'Issuing material out of a store needs the Inventory module, because an issue is a stock movement. '
                .'Without it, deliveries go direct to site and are costed on receipt — which is §6\'s default path and '
                .'what most contractors do for everything.'
            );
        }
    }

    private function guardQuantities(float $quantity, float $wastage): void
    {
        if ($quantity <= 0.0) {
            throw new InvalidArgumentException('An issue of nothing tells nobody anything.');
        }

        if ($wastage < 0.0) {
            throw new InvalidArgumentException('Negative wastage is not a correction.');
        }

        if ($wastage > $quantity) {
            throw new InvalidArgumentException(
                'More was wasted than left the store. Wastage is part of the quantity issued, not on top of it.'
            );
        }
    }

    private function guardCode(CostCode $code): void
    {
        if (! $code->is_active) {
            throw new InvalidArgumentException("Cost code {$code->code} is switched off and cannot take material.");
        }
    }
}
