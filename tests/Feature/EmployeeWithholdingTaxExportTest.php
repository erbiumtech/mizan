<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payslip;
use App\Services\EmployeeWithholdingTaxExport;
use OpenSpout\Reader\XLSX\Reader;
use Tests\AccountingTestCase;

class EmployeeWithholdingTaxExportTest extends AccountingTestCase
{
    public function test_it_exports_only_employee_withholding_tax_for_the_month(): void
    {
        $user = $this->makeUser('Employee', 'wht-emp@test.local');
        $employee = Employee::create([
            'user_id' => $user->id, 'employee_id' => 'EMP-WHT-1',
            'phone' => '0300-1111111', 'gender' => 'Male', 'is_active' => 1, 'nic' => '35201-1234567-1',
            'address_line_1' => '12 Mall Road', 'address_line_2' => 'LAHORE',
        ]);

        Payslip::withoutEvents(function () use ($employee): void {
            // July payslip WITH withholding tax — should be included.
            Payslip::create([
                'employee_id' => $employee->id, 'month' => 'July', 'fiscal_year_id' => $this->fiscalYear->id,
                'total_earnings' => 250000, 'withholding_tax' => 12500, 'net_salary' => 237500,
            ]);
            // July payslip with NO withholding tax — should be excluded.
            $other = Employee::create([
                'user_id' => $this->makeUser('Employee', 'wht-emp2@test.local')->id,
                'employee_id' => 'EMP-WHT-2', 'phone' => '0300-2222222', 'gender' => 'Male', 'is_active' => 1,
            ]);
            Payslip::create([
                'employee_id' => $other->id, 'month' => 'July', 'fiscal_year_id' => $this->fiscalYear->id,
                'total_earnings' => 80000, 'withholding_tax' => 0, 'net_salary' => 80000,
            ]);
            // August payslip — different month, should be excluded.
            Payslip::create([
                'employee_id' => $employee->id, 'month' => 'August', 'fiscal_year_id' => $this->fiscalYear->id,
                'total_earnings' => 250000, 'withholding_tax' => 9999, 'net_salary' => 240001,
            ]);
        });

        $query = Payslip::query()
            ->where('withholding_tax', '>', 0)
            ->where('fiscal_year_id', $this->fiscalYear->id)
            ->where('month', 'July');

        $path = tempnam(sys_get_temp_dir(), 'wht-export-').'.xlsx';
        app(EmployeeWithholdingTaxExport::class)->writeToFile($query, $path);

        $rows = $this->readRows($path);
        @unlink($path);

        // Header + exactly one employee row, matching the FBR MONTHLY DETAILS layout.
        $this->assertSame([
            'Payment Section', 'TaxPayer_NTN', 'TaxPayer_CNIC', 'TaxPayer_Name',
            'TaxPayer_City', 'TaxPayer_Address', 'TaxPayer_Status',
            'TaxPayer_Business_Name', 'Taxable_Amount', 'Tax_Amount',
        ], $rows[0]);
        $this->assertCount(2, $rows);

        $this->assertSame(EmployeeWithholdingTaxExport::SALARY_SECTION, $rows[1][0]);
        $this->assertSame('', $rows[1][1]);                     // NTN not tracked
        $this->assertSame('35201-1234567-1', $rows[1][2]);      // CNIC
        $this->assertSame($user->name, $rows[1][3]);            // Name
        $this->assertSame('LAHORE', $rows[1][4]);               // City
        $this->assertSame('12 Mall Road', $rows[1][5]);         // Address
        $this->assertSame('INDIVIDUAL', $rows[1][6]);           // Status
        $this->assertSame('250000', $rows[1][8]);               // Taxable amount
        $this->assertSame('12500', $rows[1][9]);                // Tax amount
    }

    /** @return array<int, array<int, string>> */
    protected function readRows(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($c) => (string) $c, $row->toArray());
            }
            break;
        }
        $reader->close();

        return $rows;
    }
}
