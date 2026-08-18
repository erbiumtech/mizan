<?php

namespace App\Modules\ConstructionContracts\Services;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\ConstructionContracts\Models\Variation;
use App\Modules\ConstructionContracts\Models\VariationItem;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * The variation state machine, and what approval writes — `docs/construction-management-plan.md` §9.
 *
 * ```
 * draft ─► submitted ─► priced ─┬─► approved ──────────────────────► incorporated
 *                               ├─► approved_in_principle ─► priced ─► approved ─► incorporated
 *                               └─► rejected
 * ```
 *
 * **Approval writes items; it never edits them.** An `add` creates a contract item carrying
 * `source_variation_id`; an `omit` creates a **negative** scheduled value rather than reducing the original,
 * because reducing it destroys the audit trail *and* breaks certificates already issued — the certificate's
 * "completed to date" would exceed the "scheduled value" it is measured against, which is impossible on the face
 * of the form. Only `remeasure` and `rate_change` edit in place, and they are safe because on a remeasured
 * contract the quantity was always approximate and every certificate line carries its own frozen value anyway.
 *
 * **Incorporation is separate from approval, and the separation is not ceremony.** Approval is the commercial
 * agreement; incorporation is the moment the schedule changes. A provisionally priced variation is approved in
 * principle and *not* incorporated, so the schedule keeps showing the figures the parties actually agreed while
 * the forecast already carries the ones they have not.
 */
class VariationService
{
    /**
     * Open a variation on a contract, numbered in the contract's own series.
     *
     * Refused on a draft contract: a variation to something not yet executed is an edit to the schedule, and
     * `ContractService::addItem()` is where that belongs. Allowing both would give two ways to reach the same
     * state with different audit trails.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Contract $contract, array $attributes = []): Variation
    {
        if ($contract->isDraft()) {
            throw new InvalidArgumentException(
                "{$contract->contract_number} is still a draft. Edit the "
                .strtolower($contract->vocabulary()->itemSchedule())
                .' directly — a '.strtolower($contract->vocabulary()->change())
                .' changes a schedule the parties have signed.'
            );
        }

        return TenantTransaction::run(fn (): Variation => Variation::create($attributes + [
            'contract_id' => $contract->getKey(),
            'variation_number' => $this->nextNumber($contract),
            'title' => $attributes['title'] ?? 'Untitled',
        ]));
    }

    /**
     * The next number in the contract's series — `VO-7`, `CO-7`, `CH-7`.
     *
     * Per contract and with no gaps (§8.3): a missing variation number is a question at adjudication. Read off
     * the trailing digits rather than by counting rows, so a deleted draft does not make the next one reuse a
     * number that has already been quoted in correspondence.
     */
    public function nextNumber(Contract $contract): string
    {
        $used = Variation::query()
            ->where('contract_id', $contract->getKey())
            ->pluck('variation_number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return $contract->vocabulary()->number('change', $used + 1);
    }

    /**
     * Add a line to a variation that has not yet been approved.
     *
     * An `omit` must name the line it omits and a `remeasure` or `rate_change` must too — an omission of
     * nothing in particular cannot be incorporated, and a remeasure with no target has nothing to remeasure.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(Variation $variation, array $attributes): VariationItem
    {
        if (in_array($variation->status, [Variation::STATUS_APPROVED, Variation::STATUS_INCORPORATED], true)) {
            throw new InvalidArgumentException(
                "{$variation->variation_number} is {$variation->status} and its lines are what the parties "
                .'agreed. A further change is another '.strtolower($variation->contract->vocabulary()->change()).'.'
            );
        }

        $action = $attributes['action'] ?? VariationItem::ACTION_ADD;

        if ($action !== VariationItem::ACTION_ADD && ($attributes['contract_item_id'] ?? null) === null) {
            throw new InvalidArgumentException(
                "An {$action} line must name the schedule line it acts on."
            );
        }

        if ($action === VariationItem::ACTION_ADD && ($attributes['item_no'] ?? null) === null) {
            throw new InvalidArgumentException(
                'A new line needs the item number it will print as on the schedule.'
            );
        }

        return $variation->items()->create($attributes);
    }

    /** Submitted: the contractor has put it in. The time bar for a claim runs from here, not from approval. */
    public function submit(Variation $variation): Variation
    {
        $this->requireStatus($variation, [Variation::STATUS_DRAFT], 'submitted');

        return $this->stamp($variation, [
            'status' => Variation::STATUS_SUBMITTED,
            'submitted_on' => $variation->submitted_on ?? now()->toDateString(),
        ]);
    }

    /**
     * Priced: an amount is on the table.
     *
     * The assessed amount defaults to the sum of the priced lines, because that is what the lines are for — and
     * a certifier's own assessment differing from it is exactly what the two columns exist to record (§11's
     * note on NEC4).
     */
    public function price(Variation $variation, ?float $assessed = null): Variation
    {
        $this->requireStatus(
            $variation,
            [Variation::STATUS_SUBMITTED, Variation::STATUS_PRICED, Variation::STATUS_APPROVED_IN_PRINCIPLE],
            'priced',
        );

        if ($variation->items()->doesntExist() && $assessed === null) {
            throw new InvalidArgumentException(
                "{$variation->variation_number} has no lines and no assessed amount. There is nothing to price."
            );
        }

        return $this->stamp($variation, [
            'status' => Variation::STATUS_PRICED,
            'assessed_amount' => $assessed ?? $variation->itemsTotal(),
            'priced_on' => now()->toDateString(),
        ]);
    }

    /**
     * Approved in principle, with the price still provisional.
     *
     * **The state construction actually lives in** (§9): instructed, work proceeding, price disputed for four
     * months. It is forecast and not certified, which is the whole reason it is a state rather than a note.
     *
     * The provisional flag is set here rather than asked for: an approval in principle whose price was agreed
     * would simply be an approval, and letting a caller say otherwise would put unagreed money into a
     * certificate through the one door built to keep it out.
     */
    public function approveInPrinciple(Variation $variation, string $confidence = 'medium'): Variation
    {
        $this->requireStatus(
            $variation,
            [Variation::STATUS_SUBMITTED, Variation::STATUS_PRICED],
            'approved in principle',
        );

        return $this->stamp($variation, [
            'status' => Variation::STATUS_APPROVED_IN_PRINCIPLE,
            'is_price_provisional' => true,
            'provisional_confidence' => $confidence,
        ]);
    }

    /**
     * Approved: the price is agreed and the money may be certified.
     *
     * Clearing `is_price_provisional` is the substance of this method, not bookkeeping — it is what moves the
     * variation from the forecast into the certified contract sum. An approval that left the flag set would be
     * a variation both parties agreed and no certificate could include.
     */
    public function approve(Variation $variation, ?float $amount = null): Variation
    {
        $this->requireStatus(
            $variation,
            [Variation::STATUS_PRICED, Variation::STATUS_APPROVED_IN_PRINCIPLE],
            'approved',
        );

        $approved = $amount ?? $variation->approved_amount ?? $variation->assessed_amount;

        if ($approved === null) {
            throw new InvalidArgumentException(
                "{$variation->variation_number} has no agreed amount. Approving it would add nothing to the "
                .'contract sum while reading as agreed.'
            );
        }

        return $this->stamp($variation, [
            'status' => Variation::STATUS_APPROVED,
            'approved_amount' => $approved,
            'is_price_provisional' => false,
            'provisional_confidence' => null,
            'approved_on' => now()->toDateString(),
            'approved_by' => auth()->id(),
        ]);
    }

    public function reject(Variation $variation, string $reason): Variation
    {
        $this->requireStatus(
            $variation,
            [Variation::STATUS_SUBMITTED, Variation::STATUS_PRICED, Variation::STATUS_APPROVED_IN_PRINCIPLE],
            'rejected',
        );

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection needs a reason. It is read months later, by a lawyer.');
        }

        return $this->stamp($variation, [
            'status' => Variation::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Incorporate: write the variation's lines into the contract schedule.
     *
     * Only an **approved** variation may be incorporated — never one approved in principle. The schedule is what
     * certificates are measured against, and putting a provisional price into it would certify money nobody
     * agreed, which is precisely what the two states are for.
     *
     * Idempotent by construction: each variation item records the schedule line it wrote in
     * `resulting_item_id`, so a second call has nothing left to do rather than doubling the change.
     */
    public function incorporate(Variation $variation): Variation
    {
        if ($variation->status !== Variation::STATUS_APPROVED) {
            throw new InvalidArgumentException(
                "{$variation->variation_number} is {$variation->status}. Only an approved "
                .strtolower($variation->contract->vocabulary()->change())
                .' may be written into the schedule — an approval in principle is forecast, not certified.'
            );
        }

        return TenantTransaction::run(function () use ($variation): Variation {
            foreach ($variation->items as $item) {
                if ($item->resulting_item_id !== null) {
                    continue;
                }

                match ($item->action) {
                    VariationItem::ACTION_ADD => $this->writeAddition($variation, $item),
                    VariationItem::ACTION_OMIT => $this->writeOmission($variation, $item),
                    default => $this->editInPlace($item),
                };
            }

            $variation->update([
                'status' => Variation::STATUS_INCORPORATED,
                'incorporated_at' => now(),
            ]);

            return $variation->refresh();
        });
    }

    /**
     * A new schedule line, carrying the variation that wrote it.
     *
     * `source_variation_id` is what makes a change-order line print appended to the schedule rather than
     * indistinguishable from the original bill — and what stops anybody editing it afterwards
     * (`ContractItemPolicy`).
     */
    private function writeAddition(Variation $variation, VariationItem $item): void
    {
        $created = ContractItem::create([
            'contract_id' => $variation->contract_id,
            'item_no' => $item->item_no,
            'description' => $item->description ?? $variation->title,
            'cost_code_id' => $item->cost_code_id,
            'wbs_node_id' => $item->wbs_node_id,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'rate' => $item->rate,
            // Written explicitly rather than left to quantity × rate: the contract is executed by now, so the
            // model no longer recomputes it, and a lump-sum variation line has no quantity at all.
            'scheduled_value' => $item->amount,
            'source_variation_id' => $variation->getKey(),
            'sort' => $this->nextSort($variation->contract),
        ]);

        $item->update(['resulting_item_id' => $created->getKey()]);
    }

    /**
     * An omission is its own **negative** line, never a reduction of the original.
     *
     * The original's certificates were measured against its scheduled value; reducing it retrospectively makes
     * every one of them print a completed figure exceeding the value it was completed against.
     */
    private function writeOmission(Variation $variation, VariationItem $item): void
    {
        $target = $item->contractItem;

        $created = ContractItem::create([
            'contract_id' => $variation->contract_id,
            'item_no' => ($target?->item_no ?? $item->item_no).'-OM',
            'description' => $item->description ?? 'Omit: '.($target?->description ?? $variation->title),
            'cost_code_id' => $item->cost_code_id ?? $target?->cost_code_id,
            'wbs_node_id' => $item->wbs_node_id ?? $target?->wbs_node_id,
            'unit' => $item->unit ?? $target?->unit,
            'quantity' => $item->quantity,
            'rate' => $item->rate,
            // Negative whichever sign the line was written with: an omission reduces the contract sum, and a
            // positive omission would increase it while reading as a deduction on every screen.
            'scheduled_value' => -1 * abs((float) $item->amount),
            'item_type' => ContractItem::TYPE_ADJUSTMENT,
            'source_variation_id' => $variation->getKey(),
            'sort' => $this->nextSort($variation->contract),
        ]);

        $item->update(['resulting_item_id' => $created->getKey()]);
    }

    /**
     * The one permitted in-place edit, with the old figures kept on the variation line.
     *
     * Safe because on a remeasured contract the quantity was always approximate, and because every certificate
     * line carries its own frozen cumulative value — so no issued certificate moves when this does.
     */
    private function editInPlace(VariationItem $item): void
    {
        $target = $item->contractItem;

        if ($target === null) {
            throw new InvalidArgumentException(
                "A {$item->action} line names no schedule line, so there is nothing to change."
            );
        }

        $item->update([
            'previous_quantity' => $target->quantity,
            'previous_rate' => $target->rate,
            'resulting_item_id' => $target->getKey(),
        ]);

        $quantity = $item->action === VariationItem::ACTION_REMEASURE ? $item->quantity : $target->quantity;
        $rate = $item->action === VariationItem::ACTION_RATE_CHANGE ? $item->rate : $target->rate;

        $target->update([
            'quantity' => $quantity,
            'rate' => $rate,
            // Recomputed here because the contract is executed and the model has stopped doing it — the whole
            // point of freezing at execution is that only an approved variation may move this figure.
            'scheduled_value' => ($quantity !== null && $rate !== null)
                ? round((float) $quantity * (float) $rate, 2)
                : $target->scheduled_value,
        ]);
    }

    private function nextSort(Contract $contract): int
    {
        return (int) ContractItem::query()->where('contract_id', $contract->getKey())->max('sort') + 10;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function requireStatus(Variation $variation, array $allowed, string $target): void
    {
        if (! in_array($variation->status, $allowed, true)) {
            throw new InvalidArgumentException(
                "{$variation->variation_number} is {$variation->status} and cannot be {$target}. "
                .'Allowed from: '.implode(', ', $allowed).'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stamp(Variation $variation, array $attributes): Variation
    {
        $variation->update($attributes);

        return $variation->refresh();
    }
}
