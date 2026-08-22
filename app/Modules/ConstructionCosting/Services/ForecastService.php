<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\ForecastLine;
use App\Modules\ConstructionCosting\Models\ForecastRun;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\JobBudgetLine;
use App\Modules\ConstructionCosting\Models\ProgressMeasurement;
use App\Support\TenantTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Preparing and issuing a forecast — `docs/construction-management-plan.md` §3.5 and §14.
 *
 * **A forecast is a snapshot, not a mutable row.** The whole value of forecasting is comparing last month's
 * estimate at completion with this month's — *we said 4.2 in March and 4.9 in April; what moved* — and a single
 * row somebody overwrites destroys the only report that makes forecasting worth doing. So a run is prepared,
 * issued, and then left alone; next month gets its own.
 *
 * Two rules carry money here.
 *
 *  - **Every line records which of §14's three methods produced it.** "The forecast went up" and "somebody
 *    changed the method" are different facts and only one of them is news. Where the method a caller asked for
 *    cannot be computed for a line — a CPI-based estimate on a code with no measurement — the line falls back and
 *    **says which method it actually used**, rather than quietly reporting a figure the header disclaims.
 *  - **Cost to complete may not be less than the open commitment on that code.** A code with a purchase order
 *    worth more than its remaining budget is already overspent, and a forecast saying otherwise is forecasting
 *    money that has already been promised away. A lower figure is accepted **with a reason** and lands on the
 *    below-commitment exception report. There are no commitments until §5's procurement exists, so the check is
 *    live and finds nothing today — which is the point of writing it now rather than retrofitting it later.
 */
class ForecastService
{
    /**
     * Prepare a draft run for a period, one line per control account that has budget or cost.
     *
     * The budget read here is the **current** version, not the baseline: a forecast answers "what will this job
     * cost", and after an approved variation the answer is the revised budget. Earned value keeps measuring
     * against the baseline, which is the divergence §3.5 keeps the two flags for. The version used is recorded on
     * the run so a later comparison of two runs can say whether the budget moved or the forecast did.
     *
     * @param  array<int, float>  $manualCostToComplete  cost code id => the surveyor's own figure
     * @param  array<int, string>  $belowCommitmentReasons  cost code id => why a below-commitment figure stands
     */
    public function prepare(
        Job $job,
        string $periodStart,
        string $method = ForecastLine::EAC_MANUAL,
        array $manualCostToComplete = [],
        array $belowCommitmentReasons = [],
        ?string $name = null,
    ): ForecastRun {
        if (! in_array($method, [ForecastLine::EAC_MANUAL, ForecastLine::EAC_REMAINING_BUDGET, ForecastLine::EAC_CPI], true)) {
            throw new InvalidArgumentException("{$method} is not one of the three estimate-at-completion methods.");
        }

        if (ForecastRun::query()->where('job_id', $job->getKey())->whereDate('period_start', $periodStart)->exists()) {
            throw new InvalidArgumentException(
                "{$job->code} already has a forecast for {$periodStart}. A second would make \"what did we say that "
                .'month" ambiguous, which is the one question forecasting exists to answer.'
            );
        }

        $version = JobBudget::currentFor($job);

        return TenantTransaction::run(function () use ($job, $periodStart, $method, $manualCostToComplete, $belowCommitmentReasons, $name, $version): ForecastRun {
            $run = ForecastRun::create([
                'job_id' => $job->getKey(),
                'period_start' => $periodStart,
                'name' => $name,
                'status' => ForecastRun::STATUS_DRAFT,
                'budget_version_id' => $version?->getKey(),
                'prepared_by' => auth()->id(),
            ]);

            foreach ($this->controlAccounts($job, $periodStart, $version) as $codeId => $figures) {
                $this->addLine($run, $codeId, $figures, $method, $manualCostToComplete, $belowCommitmentReasons);
            }

            return $run->refresh();
        });
    }

    /**
     * Every cost code on the job that has budget or cost, with the four figures a forecast line needs.
     *
     * Budget **or** cost, not both: a code with budget and no spend is the work still to come, and a code with
     * spend and no budget is the overspend nobody planned. Dropping either is how a forecast comes to total less
     * than the job.
     *
     * @return array<int, array{budget: float, actual: float, accrued: float, earned: float}>
     */
    private function controlAccounts(Job $job, string $periodStart, ?JobBudget $version): array
    {
        $accounts = [];

        $add = function (int|string $codeId, string $key, float $amount) use (&$accounts): void {
            $accounts[(int) $codeId] ??= ['budget' => 0.0, 'actual' => 0.0, 'accrued' => 0.0, 'earned' => 0.0];
            $accounts[(int) $codeId][$key] += $amount;
        };

        if ($version) {
            foreach ($this->sumByCode(JobBudgetLine::query()->forJobTree($job)->where('budget_version_id', $version->getKey()), 'amount') as $codeId => $amount) {
                $add($codeId, 'budget', $amount);
            }
        }

        $cost = CostEntry::query()->forJobTree($job)->whereDate('posting_period', '<=', $periodStart);

        foreach ($this->sumByCode((clone $cost)->where('kind', '!=', CostEntry::KIND_ACCRUAL), 'amount') as $codeId => $amount) {
            $add($codeId, 'actual', $amount);
        }

        foreach ($this->sumByCode((clone $cost)->where('kind', CostEntry::KIND_ACCRUAL), 'amount') as $codeId => $amount) {
            $add($codeId, 'accrued', $amount);
        }

        foreach ($this->sumByCode(ProgressMeasurement::query()->forJobTree($job)->upTo($periodStart), 'earned_value') as $codeId => $amount) {
            $add($codeId, 'earned', $amount);
        }

        return $accounts;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return array<int, float>
     */
    private function sumByCode($query, string $column): array
    {
        return $query
            ->groupBy('cost_code_id')
            // Aliased rather than plucked off a raw `SUM(...)`: the result key would otherwise be whatever the
            // driver chose to call the column, which is not the same string on every driver.
            ->selectRaw("cost_code_id, SUM({$column}) as total")
            ->pluck('total', 'cost_code_id')
            ->map(fn ($amount): float => (float) $amount)
            ->all();
    }

    /**
     * @param  array{budget: float, actual: float, accrued: float, earned: float}  $figures
     * @param  array<int, float>  $manualCostToComplete
     * @param  array<int, string>  $belowCommitmentReasons
     */
    private function addLine(
        ForecastRun $run,
        int $codeId,
        array $figures,
        string $method,
        array $manualCostToComplete,
        array $belowCommitmentReasons,
    ): ForecastLine {
        [$costToComplete, $usedMethod, $note] = $this->costToComplete($figures, $method, $manualCostToComplete[$codeId] ?? null);

        $openCommitment = $this->openCommitmentFor($run->job, $codeId);
        $below = $openCommitment !== null && $costToComplete < $openCommitment;
        $reason = $belowCommitmentReasons[$codeId] ?? null;

        if ($below && ($reason === null || trim($reason) === '')) {
            $code = CostCode::query()->find($codeId);

            throw new InvalidArgumentException(
                'Cost to complete of '.number_format($costToComplete, 2)." on {$code?->code} is below the open "
                .'commitment of '.number_format($openCommitment, 2).'. That money is already promised away. Give a '
                .'reason to record the figure anyway.'
            );
        }

        return $run->lines()->create([
            'job_id' => $run->job_id,
            'cost_code_id' => $codeId,
            'cost_to_complete' => round($costToComplete, 2),
            // What was actually used, which is not always what was asked for — see the class docblock.
            'eac_method' => $usedMethod,
            'actual_to_date' => round($figures['actual'], 2),
            'accrued_to_date' => round($figures['accrued'], 2),
            'budget_at_completion' => round($figures['budget'], 2),
            'forecast_final_cost' => round($figures['actual'] + $figures['accrued'] + $costToComplete, 2),
            'open_commitment' => $openCommitment,
            'is_below_commitment' => $below,
            'below_commitment_reason' => $below ? $reason : null,
            'notes' => $note,
        ]);
    }

    /**
     * §14's three methods, and what happens when the one asked for cannot be computed.
     *
     * `cpi_based` needs a cost performance index, which needs both earned value and actual cost on that code.
     * Without them there is no trend to project, and the honest move is to fall back to remaining budget and say
     * so on the line rather than to report a figure the header claims came from a trend that does not exist.
     *
     * @param  array{budget: float, actual: float, accrued: float, earned: float}  $figures
     * @return array{0: float, 1: string, 2: string|null}
     */
    private function costToComplete(array $figures, string $method, ?float $manual): array
    {
        $spent = $figures['actual'] + $figures['accrued'];
        $remaining = max(0.0, $figures['budget'] - $spent);

        if ($method === ForecastLine::EAC_MANUAL) {
            // No figure given means the surveyor has not looked at this code yet. Remaining budget is the
            // starting point they would otherwise be typing, and the method says it came from there.
            return $manual === null
                ? [$remaining, ForecastLine::EAC_REMAINING_BUDGET, 'No manual figure given; remaining budget used.']
                : [$manual, ForecastLine::EAC_MANUAL, null];
        }

        if ($method === ForecastLine::EAC_REMAINING_BUDGET) {
            return [$remaining, ForecastLine::EAC_REMAINING_BUDGET, null];
        }

        // Budget over CPI, less what has been spent — the projection of the trend so far onto the rest.
        if ($figures['earned'] <= 0.0 || $figures['actual'] <= 0.0) {
            return [$remaining, ForecastLine::EAC_REMAINING_BUDGET, 'No cost performance index on this code yet; remaining budget used.'];
        }

        $cpi = $figures['earned'] / $figures['actual'];

        return [max(0.0, ($figures['budget'] / $cpi) - $spent), ForecastLine::EAC_CPI, null];
    }

    /**
     * The open commitment against a cost code.
     *
     * **Answered for real since Phase 5.** It was null until then — "unknown" rather than zero, because zero would
     * have meant "nothing is committed" and every below-commitment verdict taken before procurement existed would
     * have become a lie the day the first order was raised. The stand-down was written so that the rule would start
     * biting with nothing else needing to change, and this is that change: one method, repointed.
     *
     * It reads `CommitmentService`, not the table, because open commitment is **computed** — `line.amount − Σ
     * reliefs` over issued orders. The stub queried an `open_amount` column, which §5 deliberately does not have:
     * a stored balance is a second place for the same figure to live, and the first thing that goes wrong is a
     * receipt that relieves while the total does not move.
     */
    private function openCommitmentFor(Job $job, int $codeId): ?float
    {
        // Absent before the migration has run, which is the state a company mid-upgrade is in for one deploy.
        if (! DB::connection()->getSchemaBuilder()->hasTable('construction_commitment_lines')) {
            return null;
        }

        return app(CommitmentService::class)->openFor($job, $codeId);
    }

    /**
     * Issue a run, which fixes it.
     *
     * Issuing is what makes the month-on-month comparison meaningful: a draft is somebody's working paper, and an
     * issued run is what the business said. Editing after issue is refused rather than audited — the next month's
     * run is where a changed view belongs, and that is the record §3.5 wants.
     */
    public function issue(ForecastRun $run): ForecastRun
    {
        if ($run->isIssued()) {
            throw new InvalidArgumentException("That forecast was already issued on {$run->issued_at}.");
        }

        if ($run->lines()->doesntExist()) {
            throw new InvalidArgumentException(
                'A forecast with no lines would read as a job forecast to cost nothing more.'
            );
        }

        $run->update([
            'status' => ForecastRun::STATUS_ISSUED,
            'issued_at' => now(),
        ]);

        return $run->refresh();
    }

    /**
     * Two runs side by side: what moved, and by how much.
     *
     * The report the snapshot design exists for. Ordered by the size of the movement rather than by code, because
     * the question is never "what does line 03.100 say" — it is "what changed since last month", and the answer is
     * three lines out of four hundred.
     *
     * @return array<int, array<string, mixed>>
     */
    public function movement(ForecastRun $from, ForecastRun $to): array
    {
        if ($from->job_id !== $to->job_id) {
            throw new InvalidArgumentException('Those two forecasts are for different jobs.');
        }

        $before = $from->lines->keyBy('cost_code_id');
        $after = $to->lines->keyBy('cost_code_id');

        $codeIds = $before->keys()->merge($after->keys())->unique();
        $codes = CostCode::query()->whereKey($codeIds)->get()->keyBy('id');

        $rows = $codeIds->map(function ($codeId) use ($before, $after, $codes): array {
            $was = $before->get($codeId);
            $now = $after->get($codeId);

            $wasFinal = $was ? (float) $was->forecast_final_cost : null;
            $nowFinal = $now ? (float) $now->forecast_final_cost : null;

            return [
                'cost_code_id' => $codeId,
                'code' => $codes->get($codeId)?->code,
                'name' => $codes->get($codeId)?->name,
                'was' => $wasFinal,
                'now' => $nowFinal,
                // Null where the line is new or gone: a movement from nothing is not a movement of that size,
                // and showing one would put the whole of a new line into "what changed".
                'movement' => ($wasFinal === null || $nowFinal === null) ? null : round($nowFinal - $wasFinal, 2),
                'method_was' => $was?->eac_method,
                'method_now' => $now?->eac_method,
                // §14's point: the method changing is its own explanation for a figure moving.
                'method_changed' => $was && $now && $was->eac_method !== $now->eac_method,
            ];
        })->all();

        usort($rows, fn (array $a, array $b): int => abs($b['movement'] ?? 0) <=> abs($a['movement'] ?? 0));

        return $rows;
    }
}
