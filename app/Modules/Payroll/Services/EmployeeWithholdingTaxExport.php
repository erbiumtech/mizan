<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exports employee salary withholding tax (u/s 149) for a given month into the
 * FBR "MONTHLY DETAILS" XLSX layout — one row per employee payslip that has a
 * withholding tax deducted.
 */
class EmployeeWithholdingTaxExport
{
    /** FBR payment section for salary of employees u/s 149. */
    public const SALARY_SECTION = '149/4';

    /** Every employee row is filed as an individual taxpayer. */
    public const TAXPAYER_STATUS = 'INDIVIDUAL';

    /** Fallback city when the employee has no address on file. */
    public const DEFAULT_CITY = 'LAHORE';

    /** @var list<string> */
    protected array $headings = [
        'Payment Section',
        'TaxPayer_NTN',
        'TaxPayer_CNIC',
        'TaxPayer_Name',
        'TaxPayer_City',
        'TaxPayer_Address',
        'TaxPayer_Status',
        'TaxPayer_Business_Name',
        'Taxable_Amount',
        'Tax_Amount',
    ];

    /**
     * Write payslip withholding tax to an absolute file path.
     *
     * @param  Builder  $query  Payslip query already scoped to the desired month/fiscal year
     */
    public function writeToFile(Builder $query, string $path): void
    {
        $query = (clone $query)->with(['employee.user']);

        $businessName = Company::current()?->name ?? '';

        $writer = new Writer;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues($this->headings, (new Style)->setFontBold()));

        $query->orderBy('employee_id')->orderBy('id')->chunk(500, function ($payslips) use ($writer, $businessName): void {
            foreach ($payslips as $payslip) {
                $writer->addRow(Row::fromValues($this->rowFor($payslip, $businessName)));
            }
        });

        $writer->close();
    }

    /**
     * Map a Payslip onto the FBR MONTHLY DETAILS columns.
     *
     * @return array<int, string|float>
     */
    protected function rowFor(Payslip $payslip, string $businessName): array
    {
        $employee = $payslip->employee;

        return [
            self::SALARY_SECTION,
            '',                                                   // TaxPayer_NTN — not tracked per employee
            (string) ($employee?->nic ?? ''),
            trim((string) ($employee?->user?->name ?? '')),
            $this->cityFor($employee),
            $this->addressFor($employee),
            self::TAXPAYER_STATUS,
            $businessName,
            round((float) $payslip->total_earnings, 2),
            round((float) $payslip->withholding_tax, 2),
        ];
    }

    /**
     * The FBR file wants a bare city name; employees only carry two free-form
     * address lines, so use the second line (typically the city) when present.
     */
    protected function cityFor(mixed $employee): string
    {
        $city = trim((string) ($employee?->address_line_2 ?? ''));

        return $city !== '' ? $city : self::DEFAULT_CITY;
    }

    protected function addressFor(mixed $employee): string
    {
        $address = trim((string) ($employee?->address_line_1 ?? ''));

        return $address !== '' ? $address : $this->cityFor($employee);
    }
}
