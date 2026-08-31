<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Services\WithholdingService;
use App\Support\Reporting\ReportFigures;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;

/**
 * The §165 statement, as the pane draws it — `docs/erpnext-gap-plan.md` Phase 4, item 4.
 *
 * The plan says "built like `FbrTaxFile`", and the resemblance is the point: a statutory return is a listing
 * of what was deducted from whom, and this application already knows how to draw one. What differs from
 * payroll's is only which table it reads — §149 comes off payslips, §153 off payments.
 *
 * Separated from `WithholdingService` for the reason `DimensionReports` is separated from
 * `LedgerDimensionReport`: the arithmetic is testable without a payload and the payload is readable without
 * a database.
 */
class WithholdingReports
{
    use ReportShapes;

    /**
     * @return array<string, mixed>
     */
    public function statement(string $asOf, ?string $month = null): array
    {
        ['from' => $from, 'to' => $to, 'label' => $label] = $this->period($asOf, $month);

        $report = app(WithholdingService::class)->statement($from, $to);
        $totals = $report['totals'];

        $rows = array_map(fn (array $row): array => [
            Carbon::parse($row['date'])->format('j M'),
            $row['payee'],
            $row['identity'],
            $row['section'],
            ReportFigures::money($row['taxable']),
            // Trailing zeros trimmed: the statute states 11%, and "11.000%" in a column of them reads as
            // precision this figure does not have.
            rtrim(rtrim(number_format($row['rate'], 3), '0'), '.').'%',
            ReportFigures::money($row['withheld']),
        ], $report['rows']);

        return $this->table(
            'WithholdingStatement',
            'Tax Withheld (§165)',
            $this->subtitle($label),
            ['Date', 'Payee', 'NTN / CNIC', 'Section', 'Gross', 'Rate', 'Withheld'],
            '5rem minmax(0, 1fr) 9rem 8rem 9rem 5rem 9rem',
            [4, 5, 6],
            $rows,
            [
                ['label' => 'WITHHELD', 'value' => $totals['withheld'], 'accent' => true],
                ['label' => 'GROSS PAID', 'value' => $totals['taxable'], 'accent' => false],
            ],
            $this->note($report),
            $rows === [] ? null : [
                'Total — '.$totals['count'].' '.($totals['count'] === 1 ? 'deduction' : 'deductions'),
                '', '', '',
                ReportFigures::money($totals['taxable']),
                '',
                ReportFigures::money($totals['withheld']),
            ],
            'Nothing was withheld in this period.',
            // Seven columns, two of them free text. The pane clips rather than scrolls unless a table says
            // it is wide — see ReportShapes::table().
            true,
        );
    }

    /**
     * What span the statement covers.
     *
     * §165 is filed monthly *and* read for the year, so the month is a filter and not a derived period —
     * the same position `TaxSummary` and the petty cash book take, and the reason `ReportPane::ASKS` names
     * `month` for this report. With no month the statement covers the fiscal year to date, which is what
     * somebody reconciling a year of challans wants.
     *
     * The month name is resolved against the calendar year of the date, as the petty cash book does. On a 1
     * July fiscal year that reads the first half of the year correctly and the second half one year out,
     * which is a known limitation of a month *name* as a filter rather than something this report invents a
     * different answer for.
     *
     * @return array{from: string, to: string, label: string}
     */
    private function period(string $asOf, ?string $month): array
    {
        if (blank($month)) {
            ['from' => $from, 'to' => $to] = ReportPeriod::toDate($asOf);

            return [
                'from' => $from,
                'to' => $to,
                'label' => Carbon::parse($from)->format('j M Y').' to '.Carbon::parse($to)->format('j M Y'),
            ];
        }

        $date = Carbon::parse($month.' '.Carbon::parse($asOf)->year);

        return [
            'from' => $date->copy()->startOfMonth()->toDateString(),
            'to' => $date->copy()->endOfMonth()->toDateString(),
            'label' => $date->format('F Y'),
        ];
    }

    /**
     * What the reader has to know before filing it.
     *
     * The filer split, because it is the one thing on this statement a person can still get wrong after the
     * deduction is made: the rate applied was the rate the beneficiary record claimed on the day, and a
     * supplier who has since started filing was withheld from at double. Naming the count is how somebody
     * notices that their beneficiary list is out of date, which no amount of arithmetic here can tell them.
     *
     * @param  array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>, sections: array<string, float>}  $report
     */
    private function note(array $report): string
    {
        $totals = $report['totals'];

        if ($totals['count'] === 0) {
            return mb_strtoupper('no withholding section is assigned to any supplier, or none was paid in this period');
        }

        $sections = count($report['sections']);

        return mb_strtoupper(sprintf(
            '%d %s, %d %s, %d %s — %d at the non-filer rate',
            $totals['count'],
            $totals['count'] === 1 ? 'deduction' : 'deductions',
            $totals['payees'],
            $totals['payees'] === 1 ? 'payee' : 'payees',
            $sections,
            $sections === 1 ? 'section' : 'sections',
            $totals['non_filers'],
        ));
    }
}
