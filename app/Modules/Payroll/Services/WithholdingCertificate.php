<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Support\CompanyLetterhead;
use App\Support\PayrollMonth;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Carbon;

/**
 * The employer's certificate of tax deducted from one person's salary in one tax
 * year — what an employee attaches to their own return as evidence of tax already
 * paid on their behalf.
 *
 * **Reads exactly what the filed statement reads.** Rows are payslips with
 * `withholding_tax > 0`, the taxable figure is `total_earnings` — the same filter
 * and the same column the FBR monthly export and WithholdingTaxSummary use, both
 * of which state the rule this class inherits: a certificate that disagreed with
 * the statement filed against it would be worse than no certificate.
 *
 * Nothing is stored, following IncomeCertificate: the letter is rendered from the
 * payslips as they stand, so a copy already given out never disagrees with the
 * figures on file. What this deliberately does not carry: the CPR numbers of the
 * monthly deposits — they live with the employer's s.165 statements, and inventing
 * a reference this system never recorded is the one thing a certificate must not do.
 */
class WithholdingCertificate
{
    /**
     * What is missing before this certificate can be issued, in the reader's words.
     *
     * @return array<int, string>
     */
    public function missingFor(Employee $employee, FiscalYear $year): array
    {
        $missing = [];

        if (blank($employee->nic)) {
            $missing[] = 'the employee\'s CNIC (Employees → edit this employee)';
        }

        if (! $this->payslips($employee, $year)->exists()) {
            $missing[] = "any payslip with tax withheld in {$year->name} — a certificate of nothing deducted certifies nothing";
        }

        return [...$missing, ...CompanyLetterhead::missing()];
    }

    /**
     * Everything the letter prints, resolved here rather than in the template —
     * the same division of labour as IncomeCertificate.
     *
     * @return array<string, mixed>
     */
    public function data(Employee $employee, FiscalYear $year): array
    {
        $employee->loadMissing('user');

        $order = PayrollMonth::fiscalOrder($year);

        $rows = $this->payslips($employee, $year)
            ->get()
            ->map(fn (Payslip $payslip): array => [
                'month' => $payslip->month,
                'taxable' => round((float) $payslip->total_earnings, 2),
                'tax' => round((float) $payslip->withholding_tax, 2),
            ])
            ->sortBy(fn (array $row): int => $order[$row['month']] ?? 99)
            ->values();

        $issuedOn = Carbon::today();

        return [
            'employee' => $employee,
            'company' => CompanyLetterhead::data(),
            'signatory' => CompanyLetterhead::signatory(),
            'issued_on' => $issuedOn,
            'reference' => CompanyLetterhead::reference(($employee->employee_id ?: 'EMP-'.$employee->getKey()).'-WHT', $issuedOn),
            'fiscal_year' => $year->name,
            // FBR names the July–June year for the year it ends in.
            'tax_year' => $year->end_date->format('Y'),
            'rows' => $rows->all(),
            'taxable_total' => round((float) $rows->sum('taxable'), 2),
            'tax_total' => round((float) $rows->sum('tax'), 2),
        ];
    }

    public function renderPdf(Employee $employee, FiscalYear $year): PdfDocument
    {
        $name = str($employee->user?->name ?: $employee->name ?: $employee->employee_id)->slug()->value();

        return Pdf::view('pdfs.withholding-certificate', $this->data($employee, $year))
            ->format('a4')
            ->name('withholding-certificate-'.($name ?: 'employee').'-'.$year->name.'.pdf');
    }

    private function payslips(Employee $employee, FiscalYear $year)
    {
        return Payslip::query()
            ->where('employee_id', $employee->getKey())
            ->where('fiscal_year_id', $year->getKey())
            ->where('withholding_tax', '>', 0);
    }
}
