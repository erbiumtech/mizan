<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\GoodsReceiptLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;

/**
 * Delivered, costed, not yet consumed — `docs/construction-management-plan.md` §6.
 *
 * **"Exactly the gap between receipt and issue, and it is one query."** That sentence is the whole justification for
 * receipt and issue being two documents rather than one: with only a single "material used" event there is no moment at
 * which material is on site and unconsumed, and the figure cannot exist.
 *
 * It is one query because Phase 8a put the location on the movement: a job's site store *is* its materials on site, and
 * the unconsumed part of each lot — `remaining_quantity`, which the FIFO engine already maintains — is the quantity.
 * Nothing here re-derives received-minus-issued by hand, which would be a second answer to a question the lots already
 * answer.
 *
 * **Two audiences, and they want different cuts.** The cost report wants it per cost code, so it can be read beside
 * budget and actual. A payment certificate wants one figure for the job, because §10's materials-on-site line is a
 * single number the employer either accepts or does not — and §6 names that line as the second reason the two documents
 * are separate.
 *
 * **The value is at cost, and that is not the same number as the certificate's claim.** What is claimed for materials on
 * site is a contractual assessment at contract rates against a schedule line; this is what the material cost. Anything
 * that presented one as the other would be telling a certifier that their claim had been calculated for them.
 */
class MaterialsOnSite
{
    public function isAvailable(): bool
    {
        return modules()->enabled('inventory');
    }

    /**
     * What a job holds in its store, per product.
     *
     * Read from the unconsumed part of the lots rather than by netting receipts against issues: `remaining_quantity` is
     * what the FIFO engine maintains and what an issue consumes, so it is already the answer — and a second way of
     * computing it is a second figure to disagree with.
     *
     * @return array<int, array{product: Product, quantity: float, value: float}>
     */
    public function forJob(Job $job): array
    {
        if (! $this->isAvailable() || $job->stock_location_id === null) {
            return [];
        }

        $lots = $this->lots($job);

        $rows = [];

        foreach ($lots as $lot) {
            $quantity = (float) $lot->remaining_quantity;
            $rows[$lot->product_id] ??= ['product' => $lot->product, 'quantity' => 0.0, 'value' => 0.0];
            $rows[$lot->product_id]['quantity'] = round($rows[$lot->product_id]['quantity'] + $quantity, 4);
            $rows[$lot->product_id]['value'] = round(
                $rows[$lot->product_id]['value'] + ($quantity * (float) $lot->unit_cost),
                2,
            );
        }

        return array_values($rows);
    }

    /**
     * One figure for the job — what §10's certificate line is measured against.
     *
     * Zero rather than null when there is nothing on site, because zero is the true answer: a job that keeps no store
     * has no materials on site, and a null would make every screen decide separately what to print.
     */
    public function valueFor(Job $job): float
    {
        return round(array_sum(array_column($this->forJob($job), 'value')), 2);
    }

    /**
     * Per cost code, for the cost report — the code each lot was **received** against.
     *
     * The same lot-to-receipt-to-code chain Phase 8b's reclass uses, and for the same reason: until material is issued,
     * the cost is still sitting on the code the delivery arrived on, so that is where it has to be reported.
     *
     * A lot with no traceable receipt is grouped under the empty key rather than dropped or guessed at. §6's failure is
     * a figure that is right in total and wrong in every breakdown; showing the untraceable remainder as its own row is
     * what keeps the two consistent.
     *
     * @return array<int|string, array{code: ?CostCode, quantity: float, value: float}>
     */
    public function byCostCode(Job $job): array
    {
        if (! $this->isAvailable() || $job->stock_location_id === null) {
            return [];
        }

        $lots = $this->lots($job);

        $codes = GoodsReceiptLine::query()
            ->whereIn('id', $lots->pluck('source_id')->filter()->unique()->all())
            ->with('costCode')
            ->get()
            ->mapWithKeys(fn (GoodsReceiptLine $line): array => [$line->getKey() => $line->costCode]);

        $rows = [];

        foreach ($lots as $lot) {
            $code = $lot->source_id === null ? null : $codes->get($lot->source_id);
            $key = $code?->getKey() ?? '';

            $quantity = (float) $lot->remaining_quantity;

            $rows[$key] ??= ['code' => $code, 'quantity' => 0.0, 'value' => 0.0];
            $rows[$key]['quantity'] = round($rows[$key]['quantity'] + $quantity, 4);
            $rows[$key]['value'] = round($rows[$key]['value'] + ($quantity * (float) $lot->unit_cost), 2);
        }

        return $rows;
    }

    /**
     * The unconsumed lots at a job's store.
     *
     * Positive movements with something left on them: a purchase into the store, or a return that put material back.
     * Both are stock that is there, and both carry the unit cost the FIFO engine will price the next issue at.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, StockMovement>
     */
    private function lots(Job $job): \Illuminate\Database\Eloquent\Collection
    {
        return StockMovement::query()
            ->with('product')
            ->where('stock_location_id', $job->stock_location_id)
            ->where('quantity', '>', 0)
            ->where('remaining_quantity', '>', 0)
            ->get();
    }
}
