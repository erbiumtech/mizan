<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Models\SalesTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 4: the four reports, and one rule that governs all of them.
 *
 * **No second revenue number.** §8 is explicit: ageing and revenue already exist in
 * Invoicing and Accounting, and a second revenue report here would eventually disagree with
 * the ledger. Everything below is about *intentions* — what might close, what did not, and
 * what nobody has touched. None of it claims to be money earned.
 *
 * The forecast is weighted at the **stored** exchange rate. A report that read today's rate
 * would restate last quarter every morning, which §13 warns about by name.
 */
class PipelineReports
{
    /**
     * Count and value per stage, for one pipeline.
     *
     * Both weighted and plain, side by side, because §8 says sales directors want both — the
     * weighted figure for planning and the plain one for the conversation about what is
     * actually in play.
     *
     * **The closing window is optional and is what makes this the forecast's rows too.** Given
     * one, each stage counts only the deals expected to close inside it — the same aggregation,
     * one filter narrower. Added for `Crm\Support\CrmReports::forecast()`
     * (reports-expansion-plan.md Phase 1.2), whose stage rows would otherwise be the whole open
     * pipeline sitting under a total for one month: a reader adding the column would get a
     * different figure from the one above it, which is the plan's own "plausible number that is
     * wrong".
     *
     * @return array<int, array{stage: PipelineStage, count: int, value: float, weighted: float}>
     */
    public function byStage(
        Pipeline $pipeline,
        ?int $ownerEmployeeId = null,
        ?string $closingFrom = null,
        ?string $closingTo = null,
    ): array {
        return $pipeline->stages
            ->map(function (PipelineStage $stage) use ($ownerEmployeeId, $closingFrom, $closingTo): array {
                $deals = Opportunity::query()
                    ->where('pipeline_stage_id', $stage->getKey())
                    ->open()
                    ->when($ownerEmployeeId, fn ($query) => $query->where('owner_employee_id', $ownerEmployeeId))
                    // Both bounds or neither. A deal with no expected close date is not "closing
                    // outside the window", it is unforecastable — and it belongs in the unwindowed
                    // pipeline view, which is why that view does not apply this at all.
                    ->when($closingFrom && $closingTo, fn ($query) => $query
                        ->whereNotNull('expected_close_on')
                        ->whereBetween('expected_close_on', [$closingFrom, $closingTo]))
                    ->get();

                return [
                    'stage' => $stage,
                    'count' => $deals->count(),
                    'value' => round($deals->sum(fn (Opportunity $deal): float => $deal->baseAmount()), 2),
                    'weighted' => round($deals->sum(fn (Opportunity $deal): float => $deal->weightedAmount()), 2),
                ];
            })
            ->all();
    }

    /**
     * The forecast for a window, by expected close date.
     *
     * Only OPEN deals. A won deal is not a forecast — it is an invoice waiting to be raised,
     * and counting it here would double it against whatever Invoicing already says.
     *
     * A pipeline may be named, and the forecast report names one — otherwise its total covers every
     * pipeline while the stage rows under it cover one, and the two disagree by however much is in the
     * others. Left optional because the unfiltered answer is the right one for "what is coming in", which
     * is what a company with one pipeline is asking.
     *
     * @return array{weighted: float, plain: float, count: int, currencies: array<int, string>}
     */
    public function forecast(
        ?string $from = null,
        ?string $to = null,
        ?int $ownerEmployeeId = null,
        ?int $pipelineId = null,
    ): array {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->endOfMonth()->toDateString();

        $deals = Opportunity::query()
            ->open()
            ->whereNotNull('expected_close_on')
            ->whereBetween('expected_close_on', [$from, $to])
            ->when($ownerEmployeeId, fn ($query) => $query->where('owner_employee_id', $ownerEmployeeId))
            ->when($pipelineId, fn ($query) => $query->where('pipeline_id', $pipelineId))
            ->get();

        return [
            'weighted' => round($deals->sum(fn (Opportunity $deal): float => $deal->weightedAmount()), 2),
            'plain' => round($deals->sum(fn (Opportunity $deal): float => $deal->baseAmount()), 2),
            'count' => $deals->count(),
            // Named so a reader knows the total crossed currencies and was converted at the
            // rate each deal recorded — not at today's.
            'currencies' => $deals->pluck('currency_code')->filter()->unique()->values()->all(),
        ];
    }

    /**
     * Win rate by source, by owner and by lost reason.
     *
     * **Worth more than the forecast**, and the reason `lost_reasons` is a table rather than a
     * free-text box: three spellings of "too expensive" produce three rates, none of them
     * true.
     *
     * @return array{by_source: array<int, array<string, mixed>>, by_owner: array<int, array<string, mixed>>, by_lost_reason: array<int, array<string, mixed>>, won: int, lost: int, rate: ?float}
     */
    public function winLoss(?string $from = null, ?string $to = null): array
    {
        $closed = Opportunity::query()
            ->whereNotNull('outcome')
            ->when($from, fn ($query) => $query->whereDate('closed_on', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('closed_on', '<=', $to))
            ->with(['lead.source', 'owner.user', 'lostReason'])
            ->get();

        $won = $closed->where('outcome', Opportunity::OUTCOME_WON);
        $lost = $closed->where('outcome', Opportunity::OUTCOME_LOST);

        return [
            'won' => $won->count(),
            'lost' => $lost->count(),
            // Null rather than 0 for "nothing closed yet": a 0% win rate and no data at all
            // are opposite statements, and a dashboard showing 0% would be read as failure.
            'rate' => $closed->isEmpty() ? null : round($won->count() / $closed->count() * 100, 1),
            'by_source' => $this->rateBy($closed, fn (Opportunity $deal): string => $deal->lead?->source?->name ?? 'Not recorded'),
            'by_owner' => $this->rateBy($closed, fn (Opportunity $deal): string => $deal->owner?->display_label ?? 'Unassigned'),
            // Lost only: a won deal has no lost reason, and including it would put every win
            // into a "Not recorded" bucket that swamps the real reasons.
            'by_lost_reason' => $this->rateBy($lost, fn (Opportunity $deal): string => $deal->lostReason?->name ?? 'Not recorded'),
        ];
    }

    /**
     * Deals that have stopped moving, or have nothing planned.
     *
     * **The only pipeline report that changes behaviour.** The others describe; this one hands
     * somebody a list to act on — which is why "no open next action" counts as rotting
     * alongside "has not moved", and is usually the more damning of the two.
     *
     * @return Collection<int, array{opportunity: Opportunity, reason: string, days: ?int}>
     */
    public function rotting(?int $ownerEmployeeId = null): Collection
    {
        return Opportunity::query()
            ->open()
            ->when($ownerEmployeeId, fn ($query) => $query->where('owner_employee_id', $ownerEmployeeId))
            ->with(['stage', 'stageHistory', 'owner.user'])
            ->get()
            ->map(function (Opportunity $deal): ?array {
                $stale = $deal->isRotting();
                $unplanned = ! $deal->hasOpenNextAction();

                if (! $stale && ! $unplanned) {
                    return null;
                }

                $since = $deal->stageHistory->last()?->moved_at ?? $deal->created_at;

                return [
                    'opportunity' => $deal,
                    'reason' => match (true) {
                        $stale && $unplanned => 'Not moved, and nothing planned',
                        $stale => 'Not moved',
                        default => 'Nothing planned',
                    },
                    'days' => $since ? (int) $since->diffInDays(now()) : null,
                ];
            })
            ->filter()
            ->sortByDesc('days')
            ->values();
    }

    /**
     * Calls and meetings logged per employee, for a window.
     *
     * **A management number that must be read as EFFORT, not as performance** — §8 says so and
     * it is worth repeating wherever this figure is shown. Somebody with forty calls and no
     * wins may be working a harder patch.
     *
     * @return array<int, array{employee: string, activities: int}>
     */
    public function activity(?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->endOfMonth()->toDateString();

        return Activity::query()
            ->contact()
            ->between($from, $to)
            ->with('employee.user')
            ->get()
            ->groupBy(fn (Activity $activity): string => $activity->employee?->display_label ?? 'Unattributed')
            ->map(fn (Collection $group, string $employee): array => [
                'employee' => $employee,
                'activities' => $group->count(),
            ])
            ->sortByDesc('activities')
            ->values()
            ->all();
    }

    /**
     * Phase 7: attainment against a target.
     *
     * Produces the number. **Paying it is a human act** — §3 refuses to compute a commission
     * into payroll, because the first disputed deal would become a payroll incident.
     *
     * @return array<int, array{target: SalesTarget, achieved: float, attainment_pct: ?float}>
     */
    public function attainment(?string $on = null): array
    {
        $on = $on ?: now()->toDateString();

        return SalesTarget::query()
            ->covering($on)
            ->with('employee.user')
            ->get()
            ->map(function (SalesTarget $target): array {
                $achieved = match ($target->kind) {
                    SalesTarget::KIND_NEW_LEADS => (float) Lead::query()
                        ->where('owner_employee_id', $target->employee_id)
                        ->whereBetween('created_at', [
                            Carbon::parse($target->period_start)->startOfDay(),
                            Carbon::parse($target->period_end)->endOfDay(),
                        ])
                        ->count(),

                    SalesTarget::KIND_ACTIVITIES => (float) Activity::query()
                        ->contact()
                        ->where('employee_id', $target->employee_id)
                        ->between($target->period_start->toDateString(), $target->period_end->toDateString())
                        ->count(),

                    // Won VALUE, at the rate each deal recorded.
                    default => round(Opportunity::query()
                        ->won()
                        ->where('owner_employee_id', $target->employee_id)
                        ->whereBetween('closed_on', [
                            $target->period_start->toDateString(),
                            $target->period_end->toDateString(),
                        ])
                        ->get()
                        ->sum(fn (Opportunity $deal): float => $deal->baseAmount()), 2),
                };

                $goal = (float) $target->target_amount;

                return [
                    'target' => $target,
                    'achieved' => $achieved,
                    'attainment_pct' => $goal > 0 ? round($achieved / $goal * 100, 1) : null,
                ];
            })
            ->all();
    }

    /**
     * Win rate grouped by whatever the callback names.
     *
     * @param  Collection<int, Opportunity>  $deals
     * @return array<int, array<string, mixed>>
     */
    private function rateBy(Collection $deals, callable $key): array
    {
        return $deals
            ->groupBy($key)
            ->map(function (Collection $group, string $label): array {
                $won = $group->where('outcome', Opportunity::OUTCOME_WON);

                return [
                    'label' => $label,
                    'won' => $won->count(),
                    'lost' => $group->where('outcome', Opportunity::OUTCOME_LOST)->count(),
                    'total' => $group->count(),
                    'value' => round($won->sum(fn (Opportunity $deal): float => $deal->baseAmount()), 2),
                    'rate' => $group->isEmpty() ? null : round($won->count() / $group->count() * 100, 1),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }
}
