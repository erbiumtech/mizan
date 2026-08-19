<?php

namespace App\Modules\ConstructionCosting\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\LabourRate;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Support\ResolvedLabourRate;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Which rate applies, as at a date — `docs/construction-management-plan.md` §7.2.
 *
 * **`job+trade -> job -> worker/employee -> trade -> company default`**, and the order is the plan's rather than the
 * obvious one: a job-level rate beats a person-level rate, because a site allowance applies to everybody on that site
 * including the people who carry their own rate elsewhere.
 *
 * Three rules live here rather than in a form or an index.
 *
 *  - **A row is a candidate only if nothing it names contradicts the question.** A rate scoped to job 7 can never be
 *    picked for job 9, and one scoped to a worker can never be picked for somebody else. Everything null is the
 *    company default, which is why that tier needs no special case.
 *  - **The three figures resolve independently.** `overtime_multiplier` and `burden_percent` are nullable so a row can
 *    revise the rate without restating terms the company set once, so each field takes the first candidate that
 *    actually states it. A single "first matching row wins" would make a job's site-allowance row silently drop the
 *    company's overtime terms to nothing.
 *  - **Two rows for the same scope may not overlap in time**, and `set()` refuses it. The index cannot: nulls in a
 *    unique index are distinct in both MySQL and SQLite, and every scope column here is nullable, so the database
 *    would accept two open-ended company defaults and the resolver would quietly pick one of them. That is the same
 *    judgement Phase 3 made for one progress measurement per control account per period.
 *
 * **Nothing here invents a rate.** `resolve()` returns null when no row applies, and the caller has to refuse rather
 * than book an hour at zero — §18.1's rule about a healthy figure hiding an absence, which is what a labour cost of
 * 0.00 on a full week looks like.
 */
class LabourRateService
{
    /**
     * The rate in force for a question, or null when the company has not set one.
     *
     * The trade and the employee are **inferred from the worker when the caller does not give them**, because a site
     * sheet names a person and the ladder asks about a trade: making every caller remember to pass
     * `$worker->trade_id` is how a trade-level rate comes to be ignored for exactly the people it was written for.
     */
    public function resolve(
        ?Job $job = null,
        ?Trade $trade = null,
        ?Worker $worker = null,
        int|string|null $employeeId = null,
        ?string $on = null,
    ): ?ResolvedLabourRate {
        $on = Carbon::parse($on ?? now())->toDateString();

        $tradeId = $trade?->getKey() ?? $worker?->trade_id;
        $employeeId ??= $worker?->employee_id;

        $candidates = $this->candidates($job?->getKey(), $tradeId, $worker?->getKey(), $employeeId, $on);

        if ($candidates->isEmpty()) {
            return null;
        }

        /** @var LabourRate $winner */
        $winner = $candidates->first();

        $overtime = $candidates->first(fn (LabourRate $rate): bool => $rate->overtime_multiplier !== null);
        $burden = $candidates->first(fn (LabourRate $rate): bool => $rate->burden_percent !== null);

        return new ResolvedLabourRate(
            costRatePerHour: (float) $winner->cost_rate_per_hour,
            overtimeMultiplier: (float) ($overtime?->overtime_multiplier ?? config('construction.labour.overtime_multiplier')),
            burdenPercent: (float) ($burden?->burden_percent ?? config('construction.labour.burden_percent')),
            rateId: $winner->getKey(),
            tier: $winner->tier(),
            overtimeFromRate: $overtime !== null,
            burdenFromRate: $burden !== null,
        );
    }

    /**
     * Every row that could answer the question, most specific first.
     *
     * Ordered in PHP rather than in SQL because the tier is derived from which columns are null, and a `CASE` over
     * four nullable columns in an `ORDER BY` is a query nobody will read correctly in a year — while `tier()` on the
     * model can be checked against §7.2 line by line.
     *
     * Ties break on the later `effective_from`, then the higher id: two rows at the same tier on the same day is the
     * state `set()` refuses, so this only decides between a revision and the row it replaced on the boundary date.
     *
     * @return Collection<int, LabourRate>
     */
    private function candidates(
        int|string|null $jobId,
        int|string|null $tradeId,
        int|string|null $workerId,
        int|string|null $employeeId,
        string $on,
    ): Collection {
        return LabourRate::query()
            ->with(['job', 'trade', 'worker'])
            ->inForceOn($on)
            // A row may name nothing, or it may name exactly what was asked. Anything else is another question's row.
            ->where(fn ($q) => $jobId === null ? $q->whereNull('job_id') : $q->whereNull('job_id')->orWhere('job_id', $jobId))
            ->where(fn ($q) => $tradeId === null ? $q->whereNull('trade_id') : $q->whereNull('trade_id')->orWhere('trade_id', $tradeId))
            ->where(fn ($q) => $workerId === null ? $q->whereNull('worker_id') : $q->whereNull('worker_id')->orWhere('worker_id', $workerId))
            ->where(fn ($q) => $employeeId === null ? $q->whereNull('employee_id') : $q->whereNull('employee_id')->orWhere('employee_id', $employeeId))
            ->get()
            ->sortByDesc(fn (LabourRate $rate): string => sprintf(
                '%d-%s-%012d',
                $rate->tier(),
                $rate->effective_from->format('Ymd'),
                $rate->getKey(),
            ))
            ->values();
    }

    /**
     * Set a rate for a scope from a date.
     *
     * **Refuses an overlap at the same scope**, and the message says what to do instead: a wage revision is
     * `revise()`, which closes the row it supersedes rather than leaving two rows in force with nothing saying which
     * of them the cost report used.
     *
     * @param  array{job_id?: int|string|null, trade_id?: int|string|null, worker_id?: int|string|null, employee_id?: int|string|null}  $scope
     * @param  array<string, mixed>  $attributes  overtime_multiplier, burden_percent, effective_to, notes
     */
    public function set(array $scope, float $ratePerHour, string $from, array $attributes = []): LabourRate
    {
        $scope = $this->normaliseScope($scope);

        if ($ratePerHour <= 0.0) {
            throw new InvalidArgumentException(
                'A cost rate of zero or less would book a week of labour at nothing, and the cost report would '
                .'show a code with room in it that has none. Set the rate somebody is actually paid.'
            );
        }

        $to = $attributes['effective_to'] ?? null;

        if ($to !== null && Carbon::parse($to)->lt(Carbon::parse($from))) {
            throw new InvalidArgumentException('A rate cannot stop applying before it starts.');
        }

        if ($clash = $this->overlapping($scope, $from, $to)) {
            throw new InvalidArgumentException(
                'A rate for that scope is already in force from '
                .$clash->effective_from->format('d M Y')
                .($clash->effective_to ? ' to '.$clash->effective_to->format('d M Y') : ' with no end date')
                .'. Two rates in force at once leaves nothing saying which one the cost report used — revise the '
                .'existing rate instead, which closes it the day before the new one starts.'
            );
        }

        return TenantTransaction::run(fn (): LabourRate => LabourRate::create($scope + [
            'cost_rate_per_hour' => $ratePerHour,
            'effective_from' => Carbon::parse($from)->toDateString(),
            'effective_to' => $to === null ? null : Carbon::parse($to)->toDateString(),
            'overtime_multiplier' => $attributes['overtime_multiplier'] ?? null,
            'burden_percent' => $attributes['burden_percent'] ?? null,
            'notes' => $attributes['notes'] ?? null,
        ]));
    }

    /**
     * A wage revision: close the rate in force at that scope the day before, and open the new one.
     *
     * This exists as one operation because it is one act, and doing it in two is how the two rows come to overlap.
     * The old row keeps its own history — §7.2's whole point is that March's cost stays what March's rate made it,
     * so the superseded row is closed rather than edited or deleted.
     *
     * @param  array{job_id?: int|string|null, trade_id?: int|string|null, worker_id?: int|string|null, employee_id?: int|string|null}  $scope
     * @param  array<string, mixed>  $attributes
     */
    public function revise(array $scope, float $ratePerHour, string $from, array $attributes = []): LabourRate
    {
        $scope = $this->normaliseScope($scope);
        $from = Carbon::parse($from);

        return TenantTransaction::run(function () use ($scope, $ratePerHour, $from, $attributes): LabourRate {
            // `whereDate` throughout, for the reason `LabourRate::scopeInForceOn()` records: a `date` cast stores a
            // time as well, so a string comparison misses the boundary day entirely.
            $current = $this->atScope($scope)
                ->whereDate('effective_from', '<', $from->toDateString())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()))
                ->orderByDesc('effective_from')
                ->first();

            $current?->update(['effective_to' => $from->copy()->subDay()->toDateString()]);

            return $this->set($scope, $ratePerHour, $from->toDateString(), $attributes);
        });
    }

    /**
     * A row at the same scope whose period overlaps, if there is one.
     *
     * Same scope means the same four columns, not "a row that would also match": a trade-level rate and a
     * company-default rate both apply to a steel fixer and are supposed to, because that is what the ladder is.
     */
    private function overlapping(array $scope, string $from, ?string $to): ?LabourRate
    {
        return $this->atScope($scope)
            ->where(function ($query) use ($from, $to): void {
                // Anything that has not ended before this one starts, and does not start after this one ends.
                $query->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from));

                if ($to !== null) {
                    $query->whereDate('effective_from', '<=', $to);
                }
            })
            ->first();
    }

    /** @param array<string, int|string|null> $scope */
    private function atScope(array $scope): \Illuminate\Database\Eloquent\Builder
    {
        $query = LabourRate::query();

        foreach (['job_id', 'trade_id', 'worker_id', 'employee_id'] as $column) {
            $value = $scope[$column] ?? null;
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    /**
     * The four scope keys, always all four, with anything else refused.
     *
     * Refused rather than ignored: `['worker' => $worker]` silently becomes a company-default rate that applies to
     * everybody in the company, which is a rate nobody meant to set and nothing would report.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, int|string|null>
     */
    private function normaliseScope(array $scope): array
    {
        $allowed = ['job_id', 'trade_id', 'worker_id', 'employee_id'];

        if ($unknown = array_diff(array_keys($scope), $allowed)) {
            throw new InvalidArgumentException(
                'A labour rate is scoped by '.implode(', ', $allowed).' and nothing else. Got: '
                .implode(', ', $unknown).'. An unrecognised key would have set a company-wide rate silently.'
            );
        }

        return [
            'job_id' => $scope['job_id'] ?? null,
            'trade_id' => $scope['trade_id'] ?? null,
            'worker_id' => $scope['worker_id'] ?? null,
            'employee_id' => $scope['employee_id'] ?? null,
        ];
    }
}
