<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\Requisition;
use App\Modules\ConstructionCosting\Models\RequisitionLine;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * Requisitions, and turning one into an order — `docs/construction-management-plan.md` §5.
 *
 * The chain this service closes: site asks, somebody approves, a buyer orders. Three properties make it worth having
 * as a document rather than a conversation.
 *
 *  - **The approval gate is before the money.** A requisition commits nothing; approving it says the need is real,
 *    and issuing the order that follows is what commits the company. Two decisions, in that order, each with its own
 *    permission.
 *  - **Ordering is partial by default.** Forty tonnes requested, twenty ordered now — the request stays open for the
 *    rest, because the outstanding quantity is `requested − Σ ordered` over the order lines that name it rather than
 *    a flag somebody has to remember to clear.
 *  - **The cost code is demanded here, not on the request.** Site asks for rebar; the buyer decides which code
 *    carries it. Demanding it on the request would teach site staff to pick whichever code lets the form save, and
 *    that looks like data.
 */
class RequisitionService
{
    /**
     * Raise a requisition against a job.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Job $job, array $attributes = []): Requisition
    {
        return TenantTransaction::run(fn (): Requisition => Requisition::create($attributes + [
            'job_id' => $job->getKey(),
            'number' => $this->nextNumber(),
            'requested_by' => auth()->id(),
            'requested_on' => now()->toDateString(),
        ]));
    }

    /** `REQ-2026-0142`. Read off the trailing digits so a cancelled request does not hand its number on. */
    public function nextNumber(?int $year = null): string
    {
        $year ??= (int) now()->year;

        $used = Requisition::query()
            ->where('number', 'like', "REQ-{$year}-%")
            ->pluck('number')
            ->map(fn (string $number): int => (int) (preg_match('/(\d+)$/', $number, $m) ? $m[1] : 0))
            ->filter()
            ->max() ?? 0;

        return sprintf('REQ-%d-%04d', $year, $used + 1);
    }

    /**
     * Add a line to a requisition that has not been approved.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addLine(Requisition $requisition, array $attributes): RequisitionLine
    {
        if (! $requisition->isEditable()) {
            throw new InvalidArgumentException(
                "{$requisition->number} is {$requisition->status}. Raise another request rather than adding to one "
                .'somebody has already approved — the approval was for what it said at the time.'
            );
        }

        if ((float) ($attributes['quantity'] ?? 0) <= 0) {
            throw new InvalidArgumentException('A requested line needs a quantity greater than zero.');
        }

        return $requisition->lines()->create($attributes);
    }

    public function submit(Requisition $requisition): Requisition
    {
        $this->requireStatus($requisition, [Requisition::STATUS_DRAFT, Requisition::STATUS_REJECTED], 'submitted');

        if ($requisition->lines()->doesntExist()) {
            throw new InvalidArgumentException("{$requisition->number} asks for nothing. Add a line first.");
        }

        return $this->stamp($requisition, ['status' => Requisition::STATUS_SUBMITTED]);
    }

    /**
     * Approve the need.
     *
     * **This commits nothing.** It says the request is real and may be ordered — the money is committed when the
     * order that follows is issued, which is a separate act by a separate person under a separate permission.
     */
    public function approve(Requisition $requisition): Requisition
    {
        $this->requireStatus(
            $requisition,
            [Requisition::STATUS_SUBMITTED, Requisition::STATUS_DRAFT],
            'approved',
        );

        if ($requisition->lines()->doesntExist()) {
            throw new InvalidArgumentException("{$requisition->number} asks for nothing to approve.");
        }

        return $this->stamp($requisition, [
            'status' => Requisition::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);
    }

    /**
     * Reject it, with a reason.
     *
     * Rejected rather than deleted, and editable afterwards: "not like that, like this" is the usual answer, and a
     * request that vanished would be re-typed from memory.
     */
    public function reject(Requisition $requisition, string $reason): Requisition
    {
        $this->requireStatus(
            $requisition,
            [Requisition::STATUS_SUBMITTED, Requisition::STATUS_APPROVED],
            'rejected',
        );

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A rejection needs a reason. Site asked for something and is entitled to know why the answer is no.'
            );
        }

        return $this->stamp($requisition, [
            'status' => Requisition::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);
    }

    public function cancel(Requisition $requisition, string $reason): Requisition
    {
        if ($requisition->status === Requisition::STATUS_CANCELLED) {
            throw new InvalidArgumentException("{$requisition->number} is already cancelled.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Cancelling a request needs a reason.');
        }

        return $this->stamp($requisition, [
            'status' => Requisition::STATUS_CANCELLED,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Raise order lines from an approved requisition, into a draft commitment.
     *
     * `$quantities` is keyed by requisition line id, so a buyer can order part of a request and split the rest
     * across suppliers — which is what actually happens. Omitting a line orders nothing for it; omitting the array
     * entirely orders whatever is still outstanding on every line.
     *
     * `$costCodes`, also keyed by line id, supplies the code where the request did not name one. **The order refuses
     * without a code**, because the committed figure has to land somewhere the cost report can read.
     *
     * @param  array<int, float>  $quantities  requisition line id => quantity to order
     * @param  array<int, int>  $costCodes  requisition line id => cost code id
     * @return array<int, \App\Modules\ConstructionCosting\Models\CommitmentLine>
     */
    public function order(
        Requisition $requisition,
        Commitment $commitment,
        array $quantities = [],
        array $costCodes = [],
    ): array {
        if (! $requisition->isOrderable()) {
            throw new InvalidArgumentException(
                "{$requisition->number} is {$requisition->status} and cannot be ordered from. Only an approved "
                .'request may be — the approval is what says the need is real.'
            );
        }

        if (! $commitment->isDraft()) {
            throw new InvalidArgumentException(
                "{$commitment->number} is {$commitment->status}. Lines are added to an order while it is a draft; "
                .'after issue the supplier is working to the copy they were sent.'
            );
        }

        $commitments = app(CommitmentService::class);
        $created = [];

        return TenantTransaction::run(function () use ($requisition, $commitment, $quantities, $costCodes, $commitments, &$created): array {
            foreach ($requisition->lines as $line) {
                $outstanding = $line->outstandingQuantity();

                if ($outstanding <= 0.0) {
                    continue;
                }

                $quantity = $quantities === [] ? $outstanding : (float) ($quantities[$line->getKey()] ?? 0);

                if ($quantity <= 0.0) {
                    continue;
                }

                if ($quantity > $outstanding) {
                    throw new InvalidArgumentException(
                        'Ordering '.rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.')
                        .' against a line with only '.rtrim(rtrim(number_format($outstanding, 4, '.', ''), '0'), '.')
                        .' outstanding. Order the rest as its own line if the need has grown, so the request and '
                        .'the orders against it still add up.'
                    );
                }

                $codeId = $costCodes[$line->getKey()] ?? $line->cost_code_id;

                if ($codeId === null) {
                    throw new InvalidArgumentException(
                        "\"{$line->description}\" has no cost code. Site asks for materials; the buyer decides which "
                        .'code carries them, and the committed figure has to land somewhere the cost report reads.'
                    );
                }

                $created[] = $commitments->addLine(
                    $commitment,
                    $requisition->job,
                    CostCode::query()->findOrFail($codeId),
                    [
                        'requisition_line_id' => $line->getKey(),
                        'description' => $line->description,
                        'quantity' => $quantity,
                        'unit_of_measure' => $line->unit_of_measure,
                        // The estimate is a starting point for the buyer, not a price: the committed figure is
                        // whatever the supplier actually quoted, typed on the order.
                        'rate' => $line->estimated_rate,
                        'amount' => $line->estimated_rate === null
                            ? 0
                            : round($quantity * (float) $line->estimated_rate, 2),
                        'product_id' => $line->product_id,
                        'wbs_node_id' => $requisition->wbs_node_id,
                    ],
                );
            }

            if ($created === []) {
                throw new InvalidArgumentException(
                    "Nothing on {$requisition->number} is still outstanding, so there is nothing to order."
                );
            }

            $this->refreshOrderedStatus($requisition->refresh());

            return $created;
        });
    }

    /**
     * Move a requisition between `approved`, `partially_ordered` and `ordered` as orders are raised.
     *
     * Derived from the order lines rather than set by whoever raised the last one: a status somebody has to remember
     * to update is a status that disagrees with the rows underneath it — and here the disagreement would hide a need
     * nobody is chasing.
     */
    public function refreshOrderedStatus(Requisition $requisition): void
    {
        /*
         * **Only genuinely terminal states are left alone.** `ordered` is *not* one of them, and treating it as
         * terminal was a real bug: an order cancelled after the request was fully ordered could never put the
         * request back on the buyer's queue, which is the exact failure this document exists to prevent — a need
         * nobody is chasing, with nothing on any screen showing it. `ordered` is derived, so it has to be able to
         * reverse.
         */
        if (in_array($requisition->status, [
            Requisition::STATUS_CANCELLED,
            Requisition::STATUS_REJECTED,
        ], true)) {
            return;
        }

        $requisition->load('lines');
        $anyOrdered = $requisition->lines->contains(fn (RequisitionLine $line): bool => $line->orderedQuantity() > 0.0);

        $requisition->update([
            'status' => match (true) {
                ! $requisition->hasOutstanding() && $anyOrdered => Requisition::STATUS_ORDERED,
                $anyOrdered => Requisition::STATUS_PARTIALLY_ORDERED,
                default => Requisition::STATUS_APPROVED,
            },
        ]);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function requireStatus(Requisition $requisition, array $allowed, string $target): void
    {
        if (! in_array($requisition->status, $allowed, true)) {
            throw new InvalidArgumentException(
                "{$requisition->number} is {$requisition->status} and cannot be {$target}. "
                .'Allowed from: '.implode(', ', $allowed).'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stamp(Requisition $requisition, array $attributes): Requisition
    {
        $requisition->update($attributes);

        return $requisition->refresh();
    }
}
