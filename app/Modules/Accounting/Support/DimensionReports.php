<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Services\LedgerDimensionReport;
use App\Support\LedgerDimensions;
use App\Support\Reporting\ReportFigures;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;

/**
 * Profit and loss by dimension, as the pane draws it — `docs/erpnext-gap-plan.md` Phase 1, item 4.
 *
 * The arithmetic is `LedgerDimensionReport`'s; this is the table around it, in the shape every other report
 * in this application uses. Separated for the reason `FixedAssetReports` and `CashCommitmentReports` are:
 * a service that answers a question is testable without a payload, and a payload that formats an answer is
 * readable without a database.
 */
class DimensionReports
{
    use ReportShapes;

    /**
     * @return array<string, mixed>
     */
    public function profitAndLoss(string $asOf, string $dimension): array
    {
        $report = app(LedgerDimensionReport::class)->for($dimension, $asOf);
        $label = LedgerDimensions::LABELS[$report['dimension']] ?? 'Dimension';

        $rows = array_map(fn (array $row): array => [
            $row['bucket'],
            ReportFigures::money($row['income']),
            ReportFigures::money($row['expense']),
            ReportFigures::money($row['profit']),
        ], $report['rows']);

        return $this->table(
            'ProfitAndLossByDimension',
            'P&L by '.$label,
            $this->subtitle(
                Carbon::parse($report['from'])->format('j M Y').' to '.Carbon::parse($report['to'])->format('j M Y')
            ),
            [$label, 'Income', 'Expense', 'Profit'],
            'minmax(0, 1fr) 10rem 10rem 10rem',
            [1, 2, 3],
            $rows,
            [
                ['label' => 'PROFIT', 'value' => $report['totals']['profit'], 'accent' => true],
                ['label' => mb_strtoupper(LedgerDimensions::UNASSIGNED), 'value' => $report['unassigned'], 'accent' => false],
            ],
            $this->note($report),
            $rows === [] ? null : [
                'Total — '.count($rows).' '.(count($rows) === 1 ? 'row' : 'rows'),
                ReportFigures::money($report['totals']['income']),
                ReportFigures::money($report['totals']['expense']),
                ReportFigures::money($report['totals']['profit']),
            ],
            'Nothing has been posted to income or expense in this period.',
        );
    }

    /**
     * What the reader has to know before believing the split.
     *
     * The unattributed share is stated as a *proportion*, because that is the number that decides whether
     * this report is useful: a company whose ledger is 5% unassigned can manage by project, and one at 60%
     * is reading a report about its invoices with everything else in a bucket. The gap plan predicted the
     * second — 57% of a demo company's entries had no source — and `accounting:backfill-entry-sources` is
     * the answer to it, so the note says so where somebody will see it.
     *
     * @param  array<string, mixed>  $report
     */
    private function note(array $report): string
    {
        $income = (float) $report['totals']['income'];
        $expense = (float) $report['totals']['expense'];
        $turnover = abs($income) + abs($expense);

        $unassigned = collect($report['rows'])->firstWhere('bucket', LedgerDimensions::UNASSIGNED);
        $share = $unassigned === null || $turnover <= 0
            ? 0.0
            : (abs((float) $unassigned['income']) + abs((float) $unassigned['expense'])) / $turnover * 100;

        if ($share < 0.5) {
            return mb_strtoupper('every posting in this period is attributed');
        }

        return mb_strtoupper(sprintf(
            '%s%% of the movement is unattributed — manual entries have no document to read a %s from. '
            .'run accounting:backfill-entry-sources for history posted before attribution',
            number_format($share, 0),
            mb_strtolower(LedgerDimensions::LABELS[$report['dimension']] ?? 'dimension'),
        ));
    }
}
