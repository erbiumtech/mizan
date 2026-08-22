<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\SalaryBankExportService;
use App\Modules\Payroll\Services\WithholdingTaxSummary;
use App\Support\Reporting\ReportShapes;
use Carbon\Carbon;

/**
 * Payroll's three reports, as the Reports explorer draws them.
 *
 * These were methods on `Accounting\Support\ReportPane`, which meant Accounting imported
 * `WithholdingTaxSummary`, `SalaryBankExportService` and `Payslip` in order to render reports it does not
 * own. See docs/module-packaging-plan.md §8 Group A. They are registered with
 * `App\Support\Reporting\ReportRenderers` by PayrollServiceProvider, so a company without Payroll has
 * three fewer reports rather than three that fail.
 *
 * The look is unchanged because the shapes are shared — `ReportShapes` is the same code the pane used,
 * moved to where both can reach it.
 */
class PayrollReports
{
    use ReportShapes;

    /** Tax withheld per employee for the year, with what it was withheld on. */
    public function taxSummary(string $asOf, ?string $month = null): array
    {
        $report = app(WithholdingTaxSummary::class)->summary($this->fiscalYear($asOf)?->getKey(), $month);
        $employees = collect($report['employees'] ?? []);

        return $this->table(
            'TaxSummary',
            'Tax Summary',
            $this->subtitle('fiscal year '.($report['fiscal_year'] ?? '—').($month ? ' · '.$month : ' · the whole year')),
            ['Employee', 'Taxable', 'Tax withheld'],
            'minmax(0, 1fr) 10rem 10rem',
            [1, 2],
            $employees->map(fn (array $row): array => [
                (string) ($row['name'] ?? $row['employee'] ?? '—'),
                number_format((float) ($row['taxable'] ?? 0), 0),
                number_format((float) ($row['tax'] ?? 0), 0),
            ])->all(),
            [
                ['label' => 'TAXABLE TOTAL', 'value' => (float) ($report['taxable_total'] ?? 0), 'accent' => false],
                ['label' => 'TAX WITHHELD', 'value' => (float) ($report['tax_total'] ?? 0), 'accent' => true],
            ],
            mb_strtoupper($employees->count().' employees with tax withheld'),
            // Each figure under the column it totals, which is what a filing is checked against.
            [
                'Total — '.$employees->count().' employees',
                number_format((float) ($report['taxable_total'] ?? 0), 0),
                number_format((float) ($report['tax_total'] ?? 0), 0),
            ],
            $month
                ? "No tax was withheld in {$month}."
                : 'No tax has been withheld in this year yet.',
        );
    }

    /**
     * The withholding statement, summarised rather than produced.
     *
     * The file itself is released from the report's own page, which records the batch — see the `file`
     * kind in ReportPane.
     *
     * @return array<string, mixed>
     */
    public function taxFile(string $asOf): array
    {
        $year = $this->fiscalYear($asOf);

        $payslips = Payslip::query()
            ->when($year !== null, fn ($query) => $query->where('fiscal_year_id', $year->id))
            ->where('withholding_tax', '>', 0);

        return $this->fileReport(
            'FbrTaxFile',
            'FBR Tax File',
            (clone $payslips)->count(),
            (float) $payslips->sum('withholding_tax'),
            'Payslips',
            $this->period($asOf, $year),
        );
    }

    /**
     * Salary payments as a bank upload file, for a payroll month.
     *
     * @return array<string, mixed>
     */
    public function salaryFile(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $year = $this->fiscalYear($asOf);

        $rows = $year === null ? [] : app(SalaryBankExportService::class)
            ->paymentsForMonth($date->format('F'), $year);

        return $this->fileReport(
            'SalaryBankFile',
            'Salary Bank File',
            count($rows),
            (float) collect($rows)->sum('amount'),
            'Payments',
            $this->period($asOf, $year),
        );
    }

    private function fiscalYear(string $asOf): ?FiscalYear
    {
        $date = Carbon::parse($asOf);

        return FiscalYear::query()
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->first() ?? FiscalYear::current();
    }

    private function period(string $asOf, ?FiscalYear $year): string
    {
        return $year?->name
            ? 'fiscal year '.$year->name
            : 'as of '.Carbon::parse($asOf)->format('j M Y');
    }
}
