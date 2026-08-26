<?php

namespace App\Modules\Lifecycle\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Models\FinalSettlement;
use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Collection;

/**
 * Final settlements, broken into their parts — `docs/reports-expansion-plan.md` Phase 3.8.
 *
 * "Composition per leaver: notice recovery, encashment, gratuity, advance and asset recoveries, net."
 *
 * **Every settlement is a proposal, and this report is the only place they are visible together.** The model
 * says it plainly: nothing here posts and nothing is paid until somebody approves it and puts it through a
 * payslip or a payment. Which means there is no ledger balance to tie to — the Phase 2 rule does not apply —
 * and the report's value is instead in three disagreements nothing else in the application can see.
 *
 * **One: a leaver with no settlement at all.** Somebody has a leaving date and nobody built their settlement.
 * No screen asks this, because every other view of a settlement starts from a settlement that exists. It is
 * the reason the report lists *leavers* rather than settlements, and why a row can have no figures on it.
 *
 * **Two: a stored net that is not the sum of its parts.** `net_amount` is written when the settlement is
 * built and again when it is approved — and *not* when it is edited. Every component is editable on the
 * resource form, so somebody who types a notice recovery into a draft leaves the stored net behind. The
 * figure of record then disagrees with the figures it is made of, and only one of them can be right.
 *
 * **Three: a draft quoting a recovery that has moved.** The builder deliberately refuses to rebuild an
 * approved settlement, "or the agreed figure would move underneath it" — but a draft has agreed nothing, and
 * the world carries on: kit comes back, advances get recovered. A draft built in January still saying an
 * employee owes for a laptop they returned in March is quietly wrong, and this is the tie to Phase 3.7 that
 * the plan draws between these two reports.
 *
 * Approved and paid settlements are *not* checked against today's records, on purpose. Their figures are
 * frozen by agreement and a report that flagged them as stale would be arguing with the agreement.
 */
class SettlementReports
{
    use ReportShapes;

    /** Column offsets the ordering reads. Named, because a bare `$row[9]` survives a column being inserted. */
    private const LEFT = 1;

    private const STATUS = 9;

    private const NOT_BUILT = 'Not built';

    public function settlements(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);

        $settlements = FinalSettlement::query()
            ->whereDate('left_on', '>=', $period['from'])
            ->whereDate('left_on', '<=', $period['to'])
            ->with('employee.user')
            ->get();

        $missing = $this->leaversWithoutSettlement($settlements, $period);

        if ($settlements->isEmpty() && $missing->isEmpty()) {
            return $this->emptySettlements($period);
        }

        $outstandingAssets = $this->outstandingAssetValue($settlements);

        $rows = [];
        $payable = 0.0;
        $owedBack = 0.0;
        $totals = ['encashment' => 0.0, 'gratuity' => 0.0, 'notice' => 0.0, 'advance' => 0.0, 'assets' => 0.0, 'other' => 0.0, 'net' => 0.0];
        $drifted = 0;
        $stale = 0;

        foreach ($settlements as $settlement) {
            // The computed net, not the stored one, because the composition columns are on the row and a row
            // whose parts do not add up to its total reads as an arithmetic error. Where the two disagree the
            // Status cell says so, which puts the disagreement in front of the reader instead of leaving them
            // to do the subtraction.
            $net = $settlement->computedNet();
            $status = $this->status($settlement, $net, $outstandingAssets);

            $rows[] = [
                (string) ($settlement->employee?->display_label ?? 'Employee #'.$settlement->employee_id),
                (string) $settlement->left_on->toDateString(),
                $this->money($settlement->leave_encashment_amount),
                $this->money($settlement->gratuity_amount),
                $this->money($settlement->notice_recovery),
                $this->money($settlement->outstanding_advance),
                $this->money($settlement->unreturned_asset_value),
                $this->money($settlement->other_deductions),
                number_format($net, 0),
                $status['label'],
            ];

            $totals['encashment'] += (float) $settlement->leave_encashment_amount;
            $totals['gratuity'] += (float) $settlement->gratuity_amount;
            $totals['notice'] += (float) $settlement->notice_recovery;
            $totals['advance'] += (float) $settlement->outstanding_advance;
            $totals['assets'] += (float) $settlement->unreturned_asset_value;
            $totals['other'] += (float) $settlement->other_deductions;
            $totals['net'] += $net;

            // Split rather than summed. A positive and a negative net add to a figure that is neither what
            // the company owes nor what it is owed, and both of those are somebody's job.
            if ($net >= 0) {
                $payable += $net;
            } else {
                $owedBack += abs($net);
            }

            $drifted += $status['drifted'] ? 1 : 0;
            $stale += $status['stale'] ? 1 : 0;
        }

        foreach ($missing as $employee) {
            $rows[] = [
                (string) $employee->display_label,
                (string) $employee->left_on->toDateString(),
                '—', '—', '—', '—', '—', '—', '—',
                self::NOT_BUILT,
            ];
        }

        $rows = $this->ordered($rows);

        return $this->table(
            'FinalSettlementsReport',
            'Final Settlements',
            $this->subtitle('leavers between '.$period['from'].' and '.$period['to']),
            ['Employee', 'Left', 'Encashment', 'Gratuity', 'Notice rec.', 'Advance', 'Kit', 'Other', 'Net', 'Status'],
            'minmax(0, 14rem) 8rem 10rem 10rem 10rem 10rem 10rem 10rem 10rem 15rem',
            [2, 3, 4, 5, 6, 7, 8],
            $rows,
            [
                ['label' => 'NET PAYABLE', 'value' => round($payable, 2), 'accent' => true],
                ['label' => 'OWED BACK', 'value' => round($owedBack, 2), 'accent' => false],
            ],
            $this->settlementNote($settlements, $missing->count(), $drifted, $stale, $payable, $owedBack),
            $rows === [] ? null : [
                'Total — '.count($rows).' leavers',
                '',
                number_format($totals['encashment'], 0),
                number_format($totals['gratuity'], 0),
                number_format($totals['notice'], 0),
                number_format($totals['advance'], 0),
                number_format($totals['assets'], 0),
                number_format($totals['other'], 0),
                number_format($totals['net'], 0),
                '',
            ],
            'Nobody left in this period.',
            // Ten columns of money and dates. Wider than the pane, so it scrolls sideways rather than being
            // silently clipped — Phase 0.2.
            wide: true,
        );
    }

    /**
     * Where this settlement stands, and whether anything about it disagrees with itself.
     *
     * The status is the plain fact — draft, approved, paid. The two suffixes are the findings, and both can
     * be true at once: a draft can have a stale asset figure *and* a stored net that no longer matches.
     *
     * @param  array<int, float>  $outstandingAssets
     * @return array{label: string, drifted: bool, stale: bool}
     */
    private function status(FinalSettlement $settlement, float $net, array $outstandingAssets): array
    {
        $drifted = $this->differs((float) $settlement->net_amount, $net);

        // Only drafts. An approved settlement's figures are frozen by agreement, and flagging them as stale
        // would be arguing with the agreement rather than reporting a problem.
        $stale = $settlement->isDraft() && $this->differs(
            $outstandingAssets[$settlement->employee_id] ?? 0.0,
            (float) $settlement->unreturned_asset_value,
        );

        return [
            'label' => implode(' · ', array_filter([
                ucfirst($settlement->status),
                $drifted ? 'net differs' : null,
                $stale ? 'kit moved' : null,
            ])),
            'drifted' => $drifted,
            'stale' => $stale,
        ];
    }

    /**
     * Whether two money figures genuinely disagree.
     *
     * **The difference is rounded before it is judged, and an `abs(...) >= 0.01` tolerance would be wrong
     * here.** Every figure being compared is a `decimal:2` column or a `round(..., 2)`, so any real
     * disagreement is at least one paisa — but float subtraction of two such figures under-shoots: 1234.56
     * minus 1234.55 is 0.009999999999990905, which a `>= 0.01` test reads as *no difference*. Four of five
     * sampled paisa-apart pairs failed that way. Rounding the difference to two places recovers the exact
     * answer, and comparing it against nought needs no tolerance at all.
     *
     * A bare `($a - $b) !== 0.0` would behave identically on today's data — every operand is a `decimal:2`
     * column or a `round(..., 2)`, so equal values subtract to exactly nought — and no test here can tell the
     * two apart, because the schema cannot express a sub-paisa input. The rounding stays as the defensive
     * form: it is the one that still answers correctly if a caller ever hands this an unrounded sum.
     */
    private function differs(float $a, float $b): bool
    {
        return round($a - $b, 2) !== 0.0;
    }

    /**
     * What each of these employees actually still holds, from the same scope Phase 3.7 reports.
     *
     * `outstanding()` and a sum of `value` — which is what `FinalSettlementBuilder::unreturnedAssets()` does,
     * so the comparison is against the figure a rebuild would produce rather than against an approximation of
     * it. Loaded for everybody in one query, because the alternative is a query per leaver.
     *
     * @param  Collection<int, FinalSettlement>  $settlements
     * @return array<int, float>
     */
    private function outstandingAssetValue(Collection $settlements): array
    {
        $ids = $settlements->pluck('employee_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return IssuedAsset::query()
            ->outstanding()
            ->whereIn('employee_id', $ids)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(value) as held')
            ->pluck('held', 'employee_id')
            ->map(fn ($held): float => round((float) $held, 2))
            ->all();
    }

    /**
     * Leavers in the period that nobody built a settlement for.
     *
     * The reason this report lists leavers rather than settlements. Every other view of a settlement starts
     * from one that exists, so an employee who left and was never settled is invisible everywhere else — and
     * that is precisely the case somebody needs to be told about.
     *
     * @param  Collection<int, FinalSettlement>  $settlements
     * @param  array{from: string, to: string}  $period
     * @return Collection<int, Employee>
     */
    private function leaversWithoutSettlement(Collection $settlements, array $period): Collection
    {
        return Employee::query()
            ->whereNotNull('left_on')
            ->whereDate('left_on', '>=', $period['from'])
            ->whereDate('left_on', '<=', $period['to'])
            ->whereNotIn('id', $settlements->pluck('employee_id')->filter()->all())
            ->with('user')
            ->get();
    }

    /**
     * Rows in the order they need acting on.
     *
     * Not built first — somebody left and nothing was prepared, which is the only row on this report where
     * the omission is total. Then anything that disagrees with itself. Then the rest by leaving date, most
     * recent first, which is the order settlements get worked through.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array<int, string>>
     */
    private function ordered(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => [$this->urgency($a), $b[self::LEFT]]
            // Leaving date reversed deliberately — `$b` before `$a` on that element alone — so urgency sorts
            // ascending and the date sorts descending inside it. Spelled out because a swapped operand is
            // exactly the kind of thing a later reader tidies into a bug.
            <=> [$this->urgency($b), $a[self::LEFT]]);

        return $rows;
    }

    /** @param  array<int, string>  $row */
    private function urgency(array $row): int
    {
        return match (true) {
            $row[self::STATUS] === self::NOT_BUILT => 0,
            // A suffix on the status is a finding; the separator is how one is marked.
            str_contains($row[self::STATUS], ' · ') => 1,
            default => 2,
        };
    }

    /**
     * A zero component shows a dash.
     *
     * Ten columns of noughts is unreadable, and every nought here is honest either way: notice recovery is
     * never computed by anything, so a nought means nobody decided one was due. The footer carries the totals
     * for anybody who wants the arithmetic.
     */
    private function money(mixed $amount): string
    {
        return (float) $amount === 0.0 ? '—' : number_format((float) $amount, 0);
    }

    /**
     * What the settlements amount to, omissions first.
     *
     * Unsettled leavers lead because nothing else in the application will mention them. The two
     * self-disagreements follow, and the money split comes last — it is the summary, not the action.
     *
     * @param  Collection<int, FinalSettlement>  $settlements
     */
    private function settlementNote(
        Collection $settlements,
        int $missing,
        int $drifted,
        int $stale,
        float $payable,
        float $owedBack,
    ): string {
        return mb_strtoupper(implode(' · ', array_filter([
            ($settlements->count() + $missing).' leavers',
            $missing > 0
                ? $missing.($missing === 1 ? ' has' : ' have').' no settlement built at all'
                : null,
            $drifted > 0
                ? $drifted.' where the stored net is not the sum of its parts'
                : null,
            $stale > 0
                ? $stale.' draft'.($stale === 1 ? '' : 's').' quoting kit that has since moved'
                : null,
            $payable > 0 ? number_format($payable, 0).' payable' : null,
            $owedBack > 0 ? number_format($owedBack, 0).' owed back to the company' : null,
        ])));
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function emptySettlements(array $period): array
    {
        return $this->table(
            'FinalSettlementsReport',
            'Final Settlements',
            $this->subtitle('leavers between '.$period['from'].' and '.$period['to']),
            ['Employee', 'Left', 'Encashment', 'Gratuity', 'Notice rec.', 'Advance', 'Kit', 'Other', 'Net', 'Status'],
            'minmax(0, 14rem) 8rem 10rem 10rem 10rem 10rem 10rem 10rem 10rem 15rem',
            [2, 3, 4, 5, 6, 7, 8],
            [],
            [
                ['label' => 'NET PAYABLE', 'value' => 0.0, 'accent' => true],
                ['label' => 'OWED BACK', 'value' => 0.0, 'accent' => false],
            ],
            'NOBODY LEFT IN THIS PERIOD',
            null,
            'Nobody left in this period.',
            wide: true,
        );
    }
}
