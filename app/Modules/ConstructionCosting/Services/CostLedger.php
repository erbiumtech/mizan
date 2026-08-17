<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostBatch;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Recording, correcting and reversing job cost — `docs/construction-management-plan.md` §3.
 *
 * Every rule that matters lives here rather than in a form, for the reason §15's state machine gives and this
 * needs more: an import, a goods receipt, a certificate and a labour run will all record cost, and a rule
 * enforced on one screen is a rule the other four walk past.
 *
 * The two that carry money:
 *
 *  - **A closed period refuses new cost, and a late invoice is not an error.** It lands in the earliest open
 *    period with `incurred_on` preserved and `is_late_for_period` set (§3.4). Reopening a signed-off month to
 *    slot one invoice in invalidates the WIP snapshot, the client certificate and the GL summary that all
 *    depended on that period's total — "silently changing a closed month is the precise failure this whole
 *    design exists to prevent".
 *  - **A correction to a hardened entry is a reversal, never an edit** (§3.3). Hardened means its period closed
 *    or it reached the general ledger. Before that an edit is allowed and audited, because three rows where one
 *    is true makes a cost report unreadable.
 */
class CostLedger
{
    /**
     * Record one cost.
     *
     * `cost_type` is read off the code **here and stored**, which is the snapshot §3.2 asks for: re-typing a
     * cost code in June must not restate March's labour/material split.
     *
     * `posting_period` is decided rather than passed. A caller that could choose its own period could put cost
     * into a closed month, which is the one thing this method exists to prevent.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(Job $job, CostCode $code, array $attributes): CostEntry
    {
        if (! $code->is_leaf) {
            throw new InvalidArgumentException(
                "Cost code {$code->code} is a heading. Booking against it would double-count in every rolled-up total."
            );
        }

        if (! $code->is_active) {
            throw new InvalidArgumentException(
                "Cost code {$code->code} is switched off and cannot take new cost."
            );
        }

        $incurredOn = Carbon::parse($attributes['incurred_on'] ?? now());
        $period = $this->periodFor($incurredOn);

        return TenantTransaction::run(fn (): CostEntry => CostEntry::create($attributes + [
            'job_id' => $job->getKey(),
            'cost_code_id' => $code->getKey(),
            // Snapshotted, deliberately.
            'cost_type' => $code->cost_type,
            'incurred_on' => $incurredOn->toDateString(),
            'posting_period' => $period['start']->toDateString(),
            'is_late_for_period' => $period['is_late'],
            'created_by' => auth()->id(),
        ]));
    }

    /**
     * Which period a cost incurred on a date belongs in, and whether that makes it late.
     *
     * The month it was incurred in when that is open. Otherwise the **earliest open** period, flagged — so the
     * Late Costs report of §3.4 can show what arrived after its month was signed off, and the closed month's
     * total stays exactly what the certificate was built on.
     *
     * @return array{start: Carbon, is_late: bool}
     */
    private function periodFor(Carbon $incurredOn): array
    {
        $natural = CostPeriod::forDate($incurredOn);

        if ($natural->isOpen()) {
            return ['start' => $natural->period_start, 'is_late' => false];
        }

        $open = CostPeriod::earliestOpen()
            ?? CostPeriod::forDate(now());

        if ($open->isClosed()) {
            throw new InvalidArgumentException(
                'Every cost period is closed. Open the current month before recording more cost.'
            );
        }

        return ['start' => $open->period_start, 'is_late' => true];
    }

    /**
     * Reverse an entry: a new row with the amount and quantity negated, pointing back.
     *
     * Not a delete and not an edit. The original stays on the ledger, which is what lets somebody answer "what
     * did we think in March, and when did we change our mind" — and is the same discipline
     * `JournalEntryService::reverse()` already keeps.
     *
     * Reversing twice is refused: two negations of one cost read as a credit nobody can explain.
     */
    public function reverse(CostEntry $entry, ?string $reason = null): CostEntry
    {
        if ($entry->isReversed()) {
            throw new InvalidArgumentException('That entry has already been reversed.');
        }

        if ($entry->isReversal()) {
            throw new InvalidArgumentException(
                'A reversal cannot itself be reversed. Record the cost again instead.'
            );
        }

        return TenantTransaction::run(function () use ($entry, $reason): CostEntry {
            $incurredOn = Carbon::parse($entry->incurred_on);
            $period = $this->periodFor($incurredOn);

            $reversal = CostEntry::create([
                'job_id' => $entry->job_id,
                'wbs_node_id' => $entry->wbs_node_id,
                'cost_code_id' => $entry->cost_code_id,
                // The original's type, not the code's as it stands now — a reversal that reclassified itself
                // would leave the pair failing to cancel in the labour/material split.
                'cost_type' => $entry->cost_type,
                'kind' => CostEntry::KIND_REVERSAL,
                'amount' => -1 * (float) $entry->amount,
                'quantity' => $entry->quantity === null ? null : -1 * (float) $entry->quantity,
                'unit_of_measure' => $entry->unit_of_measure,
                'unit_rate' => $entry->unit_rate,
                'incurred_on' => $incurredOn->toDateString(),
                'posting_period' => $period['start']->toDateString(),
                'is_late_for_period' => $period['is_late'],
                'gl_treatment' => $entry->gl_treatment === CostEntry::GL_MEMO
                    ? CostEntry::GL_MEMO
                    : CostEntry::GL_PENDING,
                'is_burden' => $entry->is_burden,
                'reverses_id' => $entry->getKey(),
                'description' => $reason ?: "Reversal of #{$entry->getKey()}",
                'reference' => $entry->reference,
                'created_by' => auth()->id(),
            ]);

            $entry->update(['reversed_by_id' => $reversal->getKey()]);

            return $reversal;
        });
    }

    /**
     * Reverse a whole batch, as one batch.
     *
     * The reason batches exist (§3.2): a month's allocation across two hundred codes is backed out by naming
     * the batch, not by re-deriving its rows from a predicate that will one day find a hundred and ninety-nine.
     * The two batches sum to zero, which is the cheapest possible proof the reversal was complete.
     */
    public function reverseBatch(CostBatch $batch, ?string $reason = null): CostBatch
    {
        if ($batch->isReversed()) {
            throw new InvalidArgumentException('That batch has already been reversed.');
        }

        return TenantTransaction::run(function () use ($batch, $reason): CostBatch {
            $reversal = CostBatch::create([
                'kind' => CostBatch::KIND_REVERSAL,
                'period_start' => CostPeriod::startFor(now())->toDateString(),
                'description' => $reason ?: "Reversal of batch #{$batch->getKey()}",
                'reversed_batch_id' => $batch->getKey(),
                'created_by' => auth()->id(),
            ]);

            foreach ($batch->entries()->whereNull('reversed_by_id')->get() as $entry) {
                $this->reverse($entry, $reason)->update(['batch_id' => $reversal->getKey()]);
            }

            return $reversal->refresh();
        });
    }

    /**
     * Edit an entry in place, refusing once it has hardened.
     *
     * §3.3 draws the line deliberately rather than absolutely, and this is where it sits.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function amend(CostEntry $entry, array $attributes): CostEntry
    {
        if (! $entry->isEditable()) {
            throw new InvalidArgumentException(
                $entry->posted_to_gl_at !== null
                    ? 'That entry has reached the general ledger. Reverse it and record the correction.'
                    : 'That entry is in a closed period. Reverse it and record the correction in the open one.'
            );
        }

        // The period is never amended directly: it is derived from `incurred_on`, so a moved date re-derives it
        // and a caller cannot smuggle cost into a closed month through an edit.
        unset($attributes['posting_period'], $attributes['is_late_for_period']);

        if (isset($attributes['incurred_on'])) {
            $period = $this->periodFor(Carbon::parse($attributes['incurred_on']));
            $attributes['posting_period'] = $period['start']->toDateString();
            $attributes['is_late_for_period'] = $period['is_late'];
        }

        $entry->update($attributes);

        return $entry->refresh();
    }

    /**
     * Close a period, recording what the job-cost ledger said at the time.
     *
     * The control total is stored rather than recomputed later, because a reconciliation computed from live data
     * cannot tell you what the figures were on the day somebody signed the certificate.
     */
    public function closePeriod(CostPeriod $period): CostPeriod
    {
        if ($period->isClosed()) {
            throw new InvalidArgumentException("{$period->label()} is already closed.");
        }

        return TenantTransaction::run(function () use ($period): CostPeriod {
            $period->update([
                'status' => CostPeriod::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'jc_control_total' => CostEntry::query()->inPeriod($period->period_start->toDateString())->sum('amount'),
            ]);

            return $period->refresh();
        });
    }

    /**
     * The cost report: one row per cost code, rolled up over a job and its sub-jobs.
     *
     * **Computed live, and the invariant of §3.2 is what makes that safe** — the sum of `amount` filtered by
     * nothing but the period *is* the cost, so there is no flag to forget and no snapshot to go stale. A
     * reversal is a negative row that cancels its original in the same sum, which is why the four columns can be
     * a single `group by` rather than a pipeline of adjustments.
     *
     * Grouped in the database rather than in PHP: a three-year job has tens of thousands of entries, and the
     * query budget §18.2 asks for is only defensible if the rows never reach the application.
     *
     * @return array<int, array{code: string, name: string, cost_type: string, quantity: float|null, amount: float}>
     */
    public function reportFor(Job $job, ?string $periodStart = null, ?string $upTo = null): array
    {
        $rows = CostEntry::query()
            ->forJobTree($job)
            ->when($periodStart, fn ($q) => $q->whereDate('posting_period', $periodStart))
            ->when($upTo, fn ($q) => $q->whereDate('posting_period', '<=', $upTo))
            ->join('construction_cost_codes', 'construction_cost_codes.id', '=', 'construction_cost_entries.cost_code_id')
            ->groupBy(
                'construction_cost_entries.cost_code_id',
                'construction_cost_codes.code',
                'construction_cost_codes.name',
                'construction_cost_entries.cost_type',
            )
            ->orderBy('construction_cost_codes.code')
            ->get([
                'construction_cost_codes.code as code',
                'construction_cost_codes.name as name',
                'construction_cost_entries.cost_type as cost_type',
                \Illuminate\Support\Facades\DB::raw('SUM(construction_cost_entries.quantity) as quantity'),
                \Illuminate\Support\Facades\DB::raw('SUM(construction_cost_entries.amount) as amount'),
            ]);

        return $rows->map(fn ($row): array => [
            'code' => $row->code,
            'name' => $row->name,
            'cost_type' => $row->cost_type,
            'quantity' => $row->quantity === null ? null : (float) $row->quantity,
            'amount' => (float) $row->amount,
            // The unit rate — §3.1's first reason this ledger exists, and unavailable from money alone. Null
            // rather than a division by zero when nothing was measured.
            'unit_rate' => ((float) $row->quantity) != 0.0
                ? round((float) $row->amount / (float) $row->quantity, 4)
                : null,
        ])->all();
    }

    /**
     * What a job has cost, over its whole tree.
     *
     * The one-line form of the invariant, and the figure everything else is checked against.
     */
    public function totalFor(Job $job, ?string $periodStart = null): float
    {
        return (float) CostEntry::query()
            ->forJobTree($job)
            ->when($periodStart, fn ($q) => $q->whereDate('posting_period', $periodStart))
            ->sum('amount');
    }
}
