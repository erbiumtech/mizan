<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Support\LedgerDimensions;
use App\Support\Reporting\ReportPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Profit and loss, read by project or by department — `docs/erpnext-gap-plan.md` Phase 1, item 4.
 *
 * The question ERPNext answers with a cost centre on every ledger line, answered instead from what
 * produced each entry. An invoice knows its project; a payslip knows its employee's department; a payment
 * knows who it was paid to. Nothing was added to `journal_entry_lines` and nothing needed to be.
 *
 * **Two queries and a lookup, not a row per posting.** The figures are aggregated in SQL by source, so the
 * database returns one row per (document, account type) pair rather than one per ledger line; the sources
 * are then loaded one query per type through the morph map. A company's year is a few thousand lines and a
 * few hundred documents, and this reads the second number.
 *
 * **The total is the check.** Every posted income and expense line in the period lands in exactly one
 * bucket, so this report's total *is* the profit and loss for the same dates. If the two ever disagree, one
 * of them has a bug — which is why the test asserts it rather than the row order.
 *
 * **Unassigned is a row, never a silence.** An entry with no source — a manual journal, an opening
 * balance, anything typed by a person — cannot be attributed and says so. The gap plan is explicit that
 * this is the one outcome worse than not having the dimension: an incomplete grouping that looks complete.
 */
class LedgerDimensionReport
{
    /**
     * Income, expense and profit per bucket for a period.
     *
     * @return array{
     *     dimension: string,
     *     from: string,
     *     to: string,
     *     rows: array<int, array{bucket: string, income: float, expense: float, profit: float}>,
     *     totals: array{income: float, expense: float, profit: float},
     *     unassigned: float
     * }
     */
    public function for(string $dimension, string $asOf): array
    {
        $dimension = array_key_exists($dimension, LedgerDimensions::LABELS)
            ? $dimension
            : LedgerDimensions::PROJECT;

        ['from' => $from, 'to' => $to] = ReportPeriod::toDate($asOf);

        $movements = $this->movements($from, $to);
        $sources = $this->sourcesFor($movements);

        $buckets = [];

        foreach ($movements as $movement) {
            $source = $movement->source_type === null
                ? null
                : ($sources[$movement->source_type][$movement->source_id] ?? null);

            $bucket = LedgerDimensions::bucket($source, $dimension);

            $buckets[$bucket] ??= ['bucket' => $bucket, 'income' => 0.0, 'expense' => 0.0];
            $buckets[$bucket][$movement->kind] += (float) $movement->amount;
        }

        $rows = collect($buckets)
            ->map(fn (array $row): array => [
                'bucket' => $row['bucket'],
                'income' => round($row['income'], 2),
                'expense' => round($row['expense'], 2),
                'profit' => round($row['income'] - $row['expense'], 2),
            ])
            // Named buckets first and alphabetically, with the remainder last: Unassigned is a real answer
            // and is deliberately not sorted among the projects as though it were one of them.
            ->sortBy(fn (array $row): string => ($row['bucket'] === LedgerDimensions::UNASSIGNED ? '1' : '0').$row['bucket'])
            ->values()
            ->all();

        $unassigned = collect($rows)->firstWhere('bucket', LedgerDimensions::UNASSIGNED);

        return [
            'dimension' => $dimension,
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'income' => round(array_sum(array_column($rows, 'income')), 2),
                'expense' => round(array_sum(array_column($rows, 'expense')), 2),
                'profit' => round(array_sum(array_column($rows, 'profit')), 2),
            ],
            // Null-checked rather than `?? 0` on an offset: a company with everything attributed has no
            // such row at all, which is the good outcome and must not be the one that errors.
            'unassigned' => round((float) ($unassigned['profit'] ?? 0), 2),
        ];
    }

    /**
     * Posted income and expense movement in the period, summed per document and account type.
     *
     * The sign convention is `FinancialReportService::periodBalance()`'s and has to stay that way: income
     * is credit-normal so its movement is credits less debits, expense the other way about. Two reports
     * disagreeing about the sign of a figure is worse than one of them not existing.
     *
     * **The sum is aliased `movement`, not `amount`, and that is not style.** `JournalEntryLine` has a
     * `getAmountAttribute()` accessor — debit or credit, whichever is set — and an accessor wins over a
     * selected column of the same name. A row hydrated from this aggregate has neither debit nor credit,
     * so the accessor answered 0.0 for every bucket and the report showed a perfect set of empty projects.
     * Silent, plausible, and entirely wrong.
     *
     * @return Collection<int, object>
     */
    private function movements(string $from, string $to): Collection
    {
        $lines = JournalEntryLine::query()->getModel()->getTable();
        $entries = (new JournalEntry)->getTable();
        $accounts = (new Account)->getTable();

        return JournalEntryLine::query()
            ->join($entries, "{$entries}.id", '=', "{$lines}.journal_entry_id")
            ->join($accounts, "{$accounts}.id", '=', "{$lines}.account_id")
            ->where("{$entries}.is_posted", true)
            ->whereBetween("{$entries}.entry_date", [$from, $to])
            ->whereIn("{$accounts}.type", ['income', 'expense'])
            ->groupBy("{$entries}.source_type", "{$entries}.source_id", "{$accounts}.type")
            ->selectRaw(implode(', ', [
                "{$entries}.source_type as source_type",
                "{$entries}.source_id as source_id",
                "{$accounts}.type as kind",
                // Credits less debits for income, debits less credits for expense — the movement, signed
                // the way each side of the profit and loss reads.
                "sum(case when {$accounts}.type = 'income'"
                    ." then {$lines}.credit_amount - {$lines}.debit_amount"
                    ." else {$lines}.debit_amount - {$lines}.credit_amount end) as movement",
            ]))
            ->get()
            ->map(fn (JournalEntryLine $row): object => (object) [
                'source_type' => $row->getAttribute('source_type'),
                'source_id' => $row->getAttribute('source_id'),
                'kind' => $row->getAttribute('kind') === 'income' ? 'income' : 'expense',
                'amount' => $row->getAttribute('movement'),
            ]);
    }

    /**
     * The documents behind those movements, one query per type.
     *
     * Through the morph map, so a source alias resolves to whatever class holds it today. A type whose
     * module is not installed — last year's stock postings in a company that has since dropped Inventory —
     * resolves to nothing and reports as unassigned rather than throwing.
     *
     * @param  Collection<int, object>  $movements
     * @return array<string, array<int|string, Model>>
     */
    private function sourcesFor(Collection $movements): array
    {
        $loaded = [];

        foreach ($movements->whereNotNull('source_type')->groupBy('source_type') as $alias => $rows) {
            // The framework's own resolution, which is the one `$entry->source` uses: `enforceMorphMap`
            // is fed from `ModuleMap::morphMap()`, so an alias resolves to whatever class holds it today.
            $class = Relation::getMorphedModel((string) $alias) ?? $alias;

            if (! class_exists($class)) {
                continue;
            }

            $loaded[$alias] = $class::query()
                // What the resolver reads, loaded before it is asked: resolving a few hundred documents
                // one relation at a time is the N+1 `preventLazyLoading` exists to catch.
                ->with(LedgerDimensions::eagerLoads((string) $alias))
                ->whereKey($rows->pluck('source_id')->unique()->all())
                ->get()
                ->keyBy(fn (Model $model): int|string => $model->getKey())
                ->all();
        }

        return $loaded;
    }
}
