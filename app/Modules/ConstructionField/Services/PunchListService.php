<?php

namespace App\Modules\ConstructionField\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\PunchInspection;
use App\Modules\ConstructionField\Models\PunchItem;
use App\Modules\ConstructionField\Models\PunchList;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Punch and snag lists — `docs/construction-management-plan.md` §16.4.
 *
 * Four rules live here, and the first is the one that connects this register to money.
 *
 *  - **An item closes only after an inspection that passed.** This is the segregation §16.4 needs, and it is
 *    *structural* rather than a permission — which is stronger, because a permission can be granted to somebody and a
 *    missing passed inspection cannot be. Closing an item releases part of §11's holdback, and closing it because
 *    somebody said it was done is how a defect gets certified away by the person who caused it.
 *  - **Every re-inspection is a row.** §16.4: "closed after three failed re-inspections is a different fact from closed
 *    first time." Counted, never inferred from status history — the same discipline `tickets.reopened_count` keeps.
 *  - **A list closes only when its items do.** A closed list with open items on it is a handover certificate nobody
 *    should have signed.
 *  - **Nothing is deleted once it has been inspected.** An item somebody walked round site and looked at is a record of
 *    what was found; a rejected item stays as "we agreed this was not a defect on the 14th", which is the answer to a
 *    question somebody asks again in month nine.
 *
 * `holdbackFor()` is what `RetentionService` reads. Until this sub-phase existed it could only take the AIA punch-list
 * holdback as zero and say in words that it did not know.
 */
class PunchListService
{
    /**
     * Open a list.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function openList(Job $job, array $attributes): PunchList
    {
        if (trim((string) ($attributes['name'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A punch list needs a name. "List 3" is what somebody has to recognise at handover, eight months after '
                .'the walk-round.'
            );
        }

        if (! array_key_exists($attributes['kind'] ?? '', PunchList::KINDS)) {
            throw new InvalidArgumentException(
                'A punch list needs a kind. An internal quality sweep and the employer\'s own list are different '
                .'documents — one can be quoted back at you and the other cannot.'
            );
        }

        return TenantTransaction::run(fn (): PunchList => PunchList::create(array_merge($attributes, [
            'job_id' => $job->getKey(),
            'opened_on' => Carbon::parse($attributes['opened_on'] ?? now())->toDateString(),
        ])));
    }

    /**
     * Add an item to a list.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addItem(PunchList $list, array $attributes): PunchItem
    {
        if ($list->isClosed()) {
            throw new InvalidArgumentException(
                "{$list->displayName()} was closed on {$list->closed_on?->toDateString()}. Open a new list rather than "
                .'adding to a closed one — a list is the occasion it was raised on, and back-dating findings into it '
                .'loses when they were actually found.'
            );
        }

        if (trim((string) ($attributes['description'] ?? '')) === '') {
            throw new InvalidArgumentException(
                'A punch item needs describing. "Snag" against a room number is a line the trade cannot price and the '
                .'inspector cannot check.'
            );
        }

        return TenantTransaction::run(fn (): PunchItem => $list->items()->create(array_merge($attributes, [
            'job_id' => $list->job_id,
            'reference' => $attributes['reference'] ?? $this->nextReference($list),
            'raised_on' => Carbon::parse($attributes['raised_on'] ?? now())->toDateString(),
        ])));
    }

    /**
     * `1` upward per list.
     *
     * Read off the trailing digits of the highest existing reference rather than counted, so an imported list that
     * starts at 100 continues from 101 — and because nothing here is ever deleted, max-plus-one has no gaps.
     */
    public function nextReference(PunchList $list): string
    {
        $used = PunchItem::query()
            ->where('punch_list_id', $list->getKey())
            ->pluck('reference')
            ->map(fn (string $reference): int => (int) (preg_match('/(\d+)$/', $reference, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return (string) ($used + 1);
    }

    /**
     * Edit an item.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateItem(PunchItem $item, array $attributes): PunchItem
    {
        if ($item->isClosed()) {
            throw new InvalidArgumentException(
                "{$item->displayName()} is {$item->status}. Raise it again on a current list if it has come back — a "
                .'defect that recurs after being signed off is its own finding, and overwriting the closed one hides '
                .'that it ever happened.'
            );
        }

        // Closing goes through an inspection. See the class docblock.
        unset($attributes['status'], $attributes['closed_on'], $attributes['closed_by']);

        $item->update($attributes);

        return $item->refresh();
    }

    /**
     * **Record a re-inspection**, which is the only way an item closes.
     *
     * The attempt number is assigned here rather than passed, so two people inspecting the same day cannot both write
     * attempt three. A pass closes the item; a failure or a partial leaves it open and the count goes up.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function inspect(PunchItem $item, array $attributes): PunchInspection
    {
        if ($item->isClosed()) {
            throw new InvalidArgumentException("{$item->displayName()} is already {$item->status}.");
        }

        if (! array_key_exists($attributes['result'] ?? '', PunchInspection::RESULTS)) {
            throw new InvalidArgumentException(
                'An inspection needs a result. Somebody walked round and looked at it, and a row that does not say '
                .'what they found is a visit nobody can act on.'
            );
        }

        $inspectedOn = Carbon::parse($attributes['inspected_on'] ?? now())->toDateString();

        return TenantTransaction::run(function () use ($item, $attributes, $inspectedOn): PunchInspection {
            $attempt = (int) $item->inspections()->max('attempt') + 1;

            $inspection = $item->inspections()->create(array_merge($attributes, [
                'attempt' => $attempt,
                'inspected_on' => $inspectedOn,
            ]));

            $item->update($inspection->passed()
                ? [
                    'status' => PunchItem::STATUS_CLOSED,
                    'closed_on' => $inspectedOn,
                    'closed_by' => auth()->id(),
                ]
                // Failed or partial: back to being put right, and the attempt count carries the fact that somebody
                // has already been out once.
                : ['status' => PunchItem::STATUS_IN_PROGRESS]);

            return $inspection->refresh();
        });
    }

    /**
     * Agree that something is not a defect.
     *
     * A status rather than a deletion, with the reason kept: "we agreed this was not a defect on the 14th" is the answer
     * to a question somebody asks again in month nine, and a deleted row answers nothing.
     */
    public function reject(PunchItem $item, string $reason): PunchItem
    {
        if ($item->isClosed()) {
            throw new InvalidArgumentException("{$item->displayName()} is already {$item->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Rejecting an item needs a reason. Somebody raised it on a walk-round, and "not a defect" with no '
                .'explanation is the row that gets raised again next month.'
            );
        }

        $item->update([
            'status' => PunchItem::STATUS_REJECTED,
            'closed_on' => now()->toDateString(),
            'closed_by' => auth()->id(),
            'notes' => trim(($item->notes ? $item->notes."\n\n" : '').'Not a defect: '.$reason),
        ]);

        return $item->refresh();
    }

    /** Mark it ready for somebody to come and look. */
    public function readyForInspection(PunchItem $item): PunchItem
    {
        if ($item->isClosed()) {
            throw new InvalidArgumentException("{$item->displayName()} is already {$item->status}.");
        }

        $item->update(['status' => PunchItem::STATUS_READY]);

        return $item->refresh();
    }

    /**
     * Record the back charge raised for an item.
     *
     * One integer, because `construction_back_charges` belongs to `construction_costing` and this module requires only
     * `construction`. §12's register is where the money goes; this is the link that stops a rectified defect looking
     * like a cost the contractor chose to absorb.
     */
    public function recordBackCharge(PunchItem $item, int|string $backChargeId): PunchItem
    {
        $item->update(['back_charge_id' => $backChargeId]);

        return $item->refresh();
    }

    /**
     * Close a list, which requires its items to be settled.
     *
     * **A closed list with open items on it is a handover certificate nobody should have signed.** The refusal names the
     * count, because "three items outstanding" is actionable where "cannot close" is not.
     */
    public function closeList(PunchList $list, ?string $on = null): PunchList
    {
        if ($list->isClosed()) {
            throw new InvalidArgumentException("{$list->displayName()} is already closed.");
        }

        $open = PunchItem::query()->where('punch_list_id', $list->getKey())->live()->count();

        if ($open > 0) {
            throw new InvalidArgumentException(
                "{$list->displayName()} still has {$open} item(s) outstanding. A closed list with open items on it is a "
                .'handover certificate nobody should have signed — close or reject them first.'
            );
        }

        $list->update([
            'status' => PunchList::STATUS_CLOSED,
            'closed_on' => Carbon::parse($on ?? now())->toDateString(),
        ]);

        return $list->refresh();
    }

    /**
     * **The punch-list holdback §11's AIA release reads.**
     *
     * The sum of `cost_to_rectify` over open items on this job that carry `affects_practical_completion`. Not every open
     * item: most snags are paint and sealant, and holding retention against all of them would make the figure
     * meaningless within a week.
     *
     * **Items with the flag and no cost estimate are counted separately, not as zero**, and the caller is told how many
     * there are. A holdback of 40,000 across twelve items where three of them have never been priced is not a holdback
     * of 40,000, and §18.1's rule is that a healthy-looking figure hiding an absence has to say so.
     *
     * @return array{amount: float, items: int, unpriced: int}
     */
    public function holdbackFor(Job $job): array
    {
        $blocking = PunchItem::query()->forJobTree($job)->blockingCompletion()->get();

        return [
            'amount' => round((float) $blocking->sum(fn (PunchItem $item): float => (float) $item->cost_to_rectify), 2),
            'items' => $blocking->count(),
            'unpriced' => $blocking->filter(fn (PunchItem $item): bool => $item->cost_to_rectify === null)->count(),
        ];
    }

    /**
     * Open items that stop the employer taking the building over.
     *
     * @return Collection<int, PunchItem>
     */
    public function blockingCompletion(Job $job): Collection
    {
        return PunchItem::query()
            ->forJobTree($job)
            ->blockingCompletion()
            ->with(['location', 'inspections'])
            ->orderByDesc('priority')
            ->get();
    }

    /**
     * **Everything still open in one place** — §16.5's named report, and the one that matters most before handover.
     *
     * Keyed by location id, with the untraceable ones under the empty key rather than dropped: a report that is right in
     * total and silently missing the items nobody located is the failure §6 already names in a different subsystem.
     *
     * @return array<int|string, Collection<int, PunchItem>>
     */
    public function byLocation(Job $job): array
    {
        return PunchItem::query()
            ->forJobTree($job)
            ->live()
            ->with('location')
            ->get()
            ->groupBy(fn (PunchItem $item): int|string => $item->location_id ?? '')
            ->all();
    }

    /**
     * @return Collection<int, PunchItem>
     */
    public function overdue(Job $job, ?string $asAt = null): Collection
    {
        return PunchItem::query()->forJobTree($job)->overdue($asAt)->orderBy('due_on')->get();
    }

    /**
     * **Items that took more than one visit** — §16.4's fact, with the evidence attached.
     *
     * `has('inspections', '>', 1)` rather than a HAVING on a `withCount` alias: SQLite refuses that as a non-aggregate,
     * and this suite tests on SQLite and ships on MySQL. Phase 9e recorded the rule.
     *
     * @return Collection<int, PunchItem>
     */
    public function neededRepeatVisits(Job $job): Collection
    {
        return PunchItem::query()
            ->forJobTree($job)
            ->has('inspections', '>', 1)
            ->with('inspections')
            ->get();
    }

    /**
     * Rectification cost with a responsible party and no back charge behind it.
     *
     * The exposure this register carries: a subcontractor's defect the main contractor put right at its own cost, priced,
     * and never recharged. Silent in exactly the way the other field registers' gaps are — cost absorbed, margin down,
     * nothing wrong anywhere.
     *
     * @return Collection<int, PunchItem>
     */
    public function unrecharged(Job $job): Collection
    {
        return PunchItem::query()
            ->forJobTree($job)
            ->whereNull('back_charge_id')
            ->whereNotNull('cost_to_rectify')
            ->where('cost_to_rectify', '>', 0)
            ->get()
            ->filter(fn (PunchItem $item): bool => $item->rechargeableAndUnbilled())
            ->values();
    }
}
