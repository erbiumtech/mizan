<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\JobBudgetLine;
use App\Modules\ConstructionCosting\Models\ProgressMeasurement;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Earned value to ANSI/EIA-748 — `docs/construction-management-plan.md` §14.
 *
 * This class exists mostly to prevent two silent failures that §14 names, and both are worth stating because both
 * produce reports full of plausible numbers that mean nothing.
 *
 * **Earned value is never derived from cost.** If it were, it would equal actual cost, `CPI` would be exactly 1.00,
 * and every job in the system would read *precisely on budget* forever. So it comes from a physical measurement
 * multiplied by the budget for that element, computed when the measurement is taken and **frozen** — because the
 * budget moves when a revision is approved and last month's earned value must not.
 *
 * **An unphased budget cannot produce a schedule variance.** `SV` and `SPI` need planned value, which needs the
 * budget to say which month it belongs to. Where it does not, this returns `null` and the report says
 * *"schedule performance unavailable: the budget is not time-phased"* — never a zero, which §14 calls the
 * archetypal silent failure in this whole family of metrics.
 */
class EarnedValue
{
    /**
     * Take a measurement, computing and freezing its earned value.
     *
     * The budget at completion is read **from the baseline**, not the current version: the baseline is what does
     * not move, and measuring against a budget that changes with every approved variation makes a run of monthly
     * earned-value figures incomparable — which is the whole point of having them.
     *
     * **One measurement per control account per period, enforced here.** The table's unique index says the same
     * thing but cannot say it where `wbs_node_id` is null — and that is the ordinary case, because most control
     * accounts are a cost code on a job with no WBS element. Both MySQL and SQLite treat nulls in a unique index as
     * distinct, so the index would let a second row through and the job's earned value would double, which reads as
     * a job ahead of schedule. Re-measuring an **unlocked** month therefore revises the figure in place; a locked
     * one is refused, because a locked measurement has already fed a certificate and a report.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function measure(Job $job, CostCode $code, string $periodStart, array $attributes): ProgressMeasurement
    {
        $baseline = JobBudget::baselineFor($job);

        if ($baseline === null) {
            throw new InvalidArgumentException(
                "{$job->code} has no baseline budget. Earned value has nothing to measure against until one is set."
            );
        }

        $wbsNodeId = $attributes['wbs_node_id'] ?? null;

        $budgetAtCompletion = $this->budgetAtCompletionFor($baseline, $code, $wbsNodeId);

        if ($budgetAtCompletion <= 0.0) {
            throw new InvalidArgumentException(
                "Cost code {$code->code} has no budget on the baseline, so there is nothing to earn against it."
            );
        }

        $existing = $this->existingMeasurement($job, $code, $periodStart, $wbsNodeId);

        if ($existing?->isLocked()) {
            throw new InvalidArgumentException(
                "{$code->code} was already measured and locked for ".Carbon::parse($periodStart)->format('M Y')
                .'. A locked measurement has been reported and certified against; measure the next period instead.'
            );
        }

        return TenantTransaction::run(function () use ($job, $code, $periodStart, $attributes, $baseline, $budgetAtCompletion, $wbsNodeId, $existing): ProgressMeasurement {
            $measurement = $existing ?? new ProgressMeasurement;

            $measurement->fill($attributes + [
                'job_id' => $job->getKey(),
                'cost_code_id' => $code->getKey(),
                'wbs_node_id' => $wbsNodeId,
                'period_start' => $periodStart,
                'measured_by' => auth()->id(),
                'measured_on' => now()->toDateString(),
            ]);

            // Derived where the method makes the percentage a consequence rather than a judgement.
            $percent = $measurement->derivedPercent();

            if ($percent < 0 || $percent > 100) {
                throw new InvalidArgumentException(
                    "Percent complete must be between 0 and 100 — got {$percent}."
                );
            }

            $measurement->fill([
                'percent_complete' => $percent,
                'budget_at_completion' => $budgetAtCompletion,
                'measured_against_version_id' => $baseline->getKey(),
                // Frozen here. Physical progress times budget, and never a function of what was spent.
                'earned_value' => round($budgetAtCompletion * ($percent / 100), 2),
            ])->save();

            return $measurement->refresh();
        });
    }

    /**
     * The measurement already taken for this control account in this period, if there is one.
     *
     * Matched on a null WBS node explicitly rather than by leaving the clause off, or the lookup would find the
     * first measurement against any element of the job and revise the wrong row.
     */
    private function existingMeasurement(Job $job, CostCode $code, string $periodStart, int|string|null $wbsNodeId): ?ProgressMeasurement
    {
        return ProgressMeasurement::query()
            ->where('job_id', $job->getKey())
            ->where('cost_code_id', $code->getKey())
            ->when($wbsNodeId === null,
                fn ($query) => $query->whereNull('wbs_node_id'),
                fn ($query) => $query->where('wbs_node_id', $wbsNodeId))
            ->whereDate('period_start', $periodStart)
            ->first();
    }

    /** The baseline budget for one control account — job, WBS node and cost code. */
    public function budgetAtCompletionFor(JobBudget $version, CostCode $code, int|string|null $wbsNodeId = null): float
    {
        return (float) JobBudgetLine::query()
            ->where('budget_version_id', $version->getKey())
            ->where('cost_code_id', $code->getKey())
            ->when($wbsNodeId !== null, fn ($q) => $q->where('wbs_node_id', $wbsNodeId))
            ->sum('amount');
    }

    /**
     * Earned value to date: the sum of frozen measurements.
     *
     * Every measurement up to and including the period, because a control account measured in March and not since
     * has still earned what it earned — reading only the latest period would report a job as having un-earned its
     * earlier work.
     */
    public function earnedValue(Job $job, string $periodStart): float
    {
        return (float) ProgressMeasurement::query()
            ->forJobTree($job)
            ->upTo($periodStart)
            ->sum('earned_value');
    }

    /**
     * Actual cost to date, excluding accruals.
     *
     * Accruals are kept out on purpose: an accrual is an estimate of cost incurred and not yet invoiced, and
     * mixing it into actual cost makes `CPI` move when nothing happened on site. §3.5's table separates the two
     * columns for the same reason.
     */
    public function actualCost(Job $job, string $periodStart): float
    {
        return (float) CostEntry::query()
            ->forJobTree($job)
            ->whereDate('posting_period', '<=', $periodStart)
            ->where('kind', '!=', CostEntry::KIND_ACCRUAL)
            ->sum('amount');
    }

    public function accrued(Job $job, string $periodStart): float
    {
        return (float) CostEntry::query()
            ->forJobTree($job)
            ->whereDate('posting_period', '<=', $periodStart)
            ->where('kind', CostEntry::KIND_ACCRUAL)
            ->sum('amount');
    }

    /**
     * Planned value — **or null when the budget is not time-phased**.
     *
     * The null is the whole point, and it propagates: `SV` and `SPI` are null with it, and the report prints
     * "unavailable" rather than a zero. §14 calls a zero meaning "no data" the archetypal silent failure here, and
     * it is right — a schedule variance of 0.00 reads as *exactly on programme*, which is the single most
     * reassuring wrong answer this module could give.
     */
    public function plannedValue(Job $job, string $periodStart): ?float
    {
        $baselineIds = JobBudget::baselineIdsForTree($job);

        if ($baselineIds->isEmpty() || ! $this->isTimePhased($baselineIds)) {
            return null;
        }

        return (float) JobBudgetLine::query()
            ->forJobTree($job)
            ->whereIn('budget_version_id', $baselineIds)
            ->phasedUpTo($periodStart)
            ->sum('amount');
    }

    /**
     * Budget at completion across a job and everything under it.
     *
     * The baselines of the tree added up, so a development reports against its towers' budgets. A parent's own
     * baseline is usually absent — budgets live on the job that was tendered — and reading only that would leave
     * every rolled-up report with real earned value and no budget to compare it to.
     */
    public function budgetAtCompletion(Job $job): ?float
    {
        $baselineIds = JobBudget::baselineIdsForTree($job);

        if ($baselineIds->isEmpty()) {
            return null;
        }

        return (float) JobBudgetLine::query()
            ->forJobTree($job)
            ->whereIn('budget_version_id', $baselineIds)
            ->sum('amount');
    }

    /** @param  \Illuminate\Support\Collection<int, int>  $baselineIds */
    private function isTimePhased($baselineIds): bool
    {
        return JobBudgetLine::query()
            ->whereIn('budget_version_id', $baselineIds)
            ->whereNotNull('period_start')
            ->exists();
    }

    /**
     * The whole set of metrics for a job at a period.
     *
     * Nulls where a figure genuinely cannot be computed, never zeros — and `schedule_note` carries the sentence
     * the report prints, so the reason travels with the absence instead of the screen having to guess it.
     *
     * @return array<string, mixed>
     */
    public function metricsFor(Job $job, string $periodStart): array
    {
        $bac = $this->budgetAtCompletion($job);
        $ev = $this->earnedValue($job, $periodStart);
        $ac = $this->actualCost($job, $periodStart);
        $pv = $this->plannedValue($job, $periodStart);

        // Cost variance and CPI need actual cost; a job with none has earned nothing to compare and says so
        // rather than dividing by zero.
        $cv = $ev - $ac;
        $cpi = $ac != 0.0 ? round($ev / $ac, 4) : null;

        $sv = $pv === null ? null : round($ev - $pv, 2);
        $spi = ($pv === null || $pv == 0.0) ? null : round($ev / $pv, 4);

        return [
            'period_start' => $periodStart,
            'budget_at_completion' => $bac,
            'earned_value' => round($ev, 2),
            'actual_cost' => round($ac, 2),
            'accrued' => round($this->accrued($job, $periodStart), 2),
            'planned_value' => $pv === null ? null : round($pv, 2),
            'cost_variance' => round($cv, 2),
            'cost_performance_index' => $cpi,
            'schedule_variance' => $sv,
            'schedule_performance_index' => $spi,
            // Why a schedule figure is missing, in the words the report shows. §14 asks for this sentence
            // specifically rather than a blank.
            'schedule_note' => $this->scheduleNote($job),
            'percent_complete' => ($bac !== null && $bac != 0.0) ? round(($ev / $bac) * 100, 2) : null,
        ];
    }

    /** The sentence a report prints where schedule performance cannot be computed. */
    private function scheduleNote(Job $job): ?string
    {
        $baselineIds = JobBudget::baselineIdsForTree($job);

        if ($baselineIds->isEmpty()) {
            return 'Schedule performance unavailable: this job has no baseline budget.';
        }

        if (! $this->isTimePhased($baselineIds)) {
            return 'Schedule performance unavailable: the budget is not time-phased.';
        }

        return null;
    }

    /**
     * Estimate at completion by each of §14's three standard methods.
     *
     * Returned together rather than one at a time so the forecaster can see what each says before choosing, and so
     * the run records which was chosen. The three disagree precisely when the variance so far is informative,
     * which is the moment the choice matters.
     *
     * @return array<string, float|null>
     */
    public function estimatesAtCompletion(Job $job, string $periodStart, float $manualCostToComplete = 0.0): array
    {
        $m = $this->metricsFor($job, $periodStart);
        $bac = (float) ($m['budget_at_completion'] ?? 0);
        $ac = (float) $m['actual_cost'];
        $ev = (float) $m['earned_value'];
        $cpi = $m['cost_performance_index'];

        return [
            // The surveyor's own judgement of what is left. Always available, and the default.
            'manual_etc' => round($ac + $manualCostToComplete, 2),
            // Where the variance so far is judged atypical and will not continue.
            'remaining_budget' => round($ac + max(0.0, $bac - $ev), 2),
            // Where it is judged typical and will continue. Null without a CPI rather than a division by zero —
            // a job with no cost yet has no trend to project.
            'cpi_based' => ($cpi !== null && $cpi != 0.0) ? round($bac / $cpi, 2) : null,
        ];
    }
}
