<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostBatch;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\ForecastRun;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\JobBudgetLine;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
                DB::raw('SUM(construction_cost_entries.quantity) as quantity'),
                DB::raw('SUM(construction_cost_entries.amount) as amount'),
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

    /**
     * §3.5's table: budget, committed, actual, accrued, cost to complete, forecast final, variance.
     *
     * **The report Phase 3 exists to produce, and the reason a contractor buys the module.** One row per cost code
     * over the job's subtree, to a period.
     *
     * Assembled in PHP from four indexed aggregates rather than as one join, and that is a deliberate trade. A
     * single query across budget lines, cost entries, forecast lines and commitments would need three outer joins
     * on a fan-out — every combination of budget line and cost entry on the same code — and would either
     * double-count or need a `distinct` that defeats the aggregation. Four grouped queries and an array merge is
     * both correct and a fixed query count regardless of how many codes the job has, which is what the query
     * budget §18.2 asks for is defensible against.
     *
     * `committed` is null until §5's procurement exists. **Null rather than zero**, deliberately: a zero would
     * read as "nothing is on order", which on a real job is almost never true and is the kind of reassuring wrong
     * answer this plan keeps guarding against.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fourColumnReport(Job $job, ?string $upToPeriod = null): array
    {
        $budget = $this->budgetByCode($job);
        $actual = $this->costByCode($job, $upToPeriod, excludeAccruals: true);
        $accrued = $this->costByCode($job, $upToPeriod, accrualsOnly: true);
        $forecast = $this->forecastByCode($job, $upToPeriod);

        $codeIds = array_unique(array_merge(
            array_keys($budget), array_keys($actual), array_keys($accrued), array_keys($forecast),
        ));

        if ($codeIds === []) {
            return [];
        }

        $codes = CostCode::query()->whereKey($codeIds)->orderBy('code')->get();

        return $codes->map(function (CostCode $code) use ($budget, $actual, $accrued, $forecast): array {
            $id = $code->getKey();

            $budgetAmount = $budget[$id] ?? 0.0;
            $actualAmount = $actual[$id] ?? 0.0;
            $accruedAmount = $accrued[$id] ?? 0.0;
            $costToComplete = $forecast[$id]['cost_to_complete'] ?? null;

            // Forecast final is actual + accrued + cost to complete. With no forecast line the honest answer is
            // "we do not know", not "it will cost exactly what it has cost" — a job with no forecast is not a job
            // finishing on its current spend.
            $forecastFinal = $costToComplete === null
                ? null
                : round($actualAmount + $accruedAmount + $costToComplete, 2);

            return [
                'cost_code_id' => $id,
                'code' => $code->code,
                'name' => $code->name,
                'cost_type' => $code->cost_type,
                'budget' => round($budgetAmount, 2),
                // Null until procurement exists — see the note above on why not zero.
                'committed' => null,
                'actual' => round($actualAmount, 2),
                'accrued' => round($accruedAmount, 2),
                'cost_to_complete' => $costToComplete,
                'eac_method' => $forecast[$id]['eac_method'] ?? null,
                'forecast_final' => $forecastFinal,
                'variance' => $forecastFinal === null ? null : round($budgetAmount - $forecastFinal, 2),
            ];
        })->all();
    }

    /**
     * @return array<int, float> cost code id => amount, from the current budget version of every job in the tree
     */
    private function budgetByCode(Job $job): array
    {
        // Every job under this one, not just this one. Cost rolls up the tree; a budget that did not would give a
        // development which is exactly on budget a variance equal to its whole spend.
        $versionIds = JobBudget::currentIdsForTree($job);

        if ($versionIds->isEmpty()) {
            return [];
        }

        return JobBudgetLine::query()
            ->forJobTree($job)
            ->whereIn('budget_version_id', $versionIds)
            ->groupBy('cost_code_id')
            // Aliased rather than plucked off a raw `SUM(amount)`: the result key would then be whatever the
            // driver chose to call the column, which is not the same string on every driver.
            ->selectRaw('cost_code_id, SUM(amount) as total')
            ->pluck('total', 'cost_code_id')
            ->map(fn ($amount): float => (float) $amount)
            ->all();
    }

    /** @return array<int, float> cost code id => amount */
    private function costByCode(Job $job, ?string $upToPeriod, bool $excludeAccruals = false, bool $accrualsOnly = false): array
    {
        return CostEntry::query()
            ->forJobTree($job)
            ->when($upToPeriod, fn ($q) => $q->whereDate('posting_period', '<=', $upToPeriod))
            ->when($excludeAccruals, fn ($q) => $q->where('kind', '!=', CostEntry::KIND_ACCRUAL))
            ->when($accrualsOnly, fn ($q) => $q->where('kind', CostEntry::KIND_ACCRUAL))
            ->groupBy('cost_code_id')
            ->selectRaw('cost_code_id, SUM(amount) as total')
            ->pluck('total', 'cost_code_id')
            ->map(fn ($amount): float => (float) $amount)
            ->all();
    }

    /**
     * The latest forecast at or before the period, per cost code.
     *
     * The *latest* rather than all of them, because a forecast is a snapshot and summing March's with April's would
     * forecast the job twice. Which run was used matters, so `eac_method` travels with the figure.
     *
     * @return array<int, array{cost_to_complete: float, eac_method: string}>
     */
    private function forecastByCode(Job $job, ?string $upToPeriod): array
    {
        // The latest run **per job**, then those added together. One run across the whole subtree would take one
        // tower's forecast and quietly drop the other's.
        $runs = ForecastRun::query()
            ->with('lines')
            ->whereIn('job_id', Job::query()->inSubtree($job)->select('id'))
            ->when($upToPeriod, fn ($q) => $q->whereDate('period_start', '<=', $upToPeriod))
            ->orderBy('period_start')
            ->get()
            ->groupBy('job_id')
            ->map(fn ($runsForJob) => $runsForJob->last());

        $lines = [];

        foreach ($runs as $run) {
            foreach ($run->lines as $line) {
                $existing = $lines[$line->cost_code_id] ?? null;

                $lines[$line->cost_code_id] = [
                    'cost_to_complete' => ($existing['cost_to_complete'] ?? 0.0) + (float) $line->cost_to_complete,
                    // Two jobs forecasting one code by different methods is a real thing to say so about: the
                    // report shows which method produced a figure, and "mixed" is the honest answer rather than
                    // whichever run happened to be read last.
                    'eac_method' => $existing === null || $existing['eac_method'] === $line->eac_method
                        ? $line->eac_method
                        : 'mixed',
                ];
            }
        }

        return $lines;
    }
}
