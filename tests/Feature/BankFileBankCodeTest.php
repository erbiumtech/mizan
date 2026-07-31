<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Beneficiary;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Payslip;
use App\Models\TransactionType;
use App\Services\BankPaymentExportService;
use App\Services\SalaryBankExportService;
use Database\Seeders\TransactionTypeSeeder;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Both iPayments files carry two bank fields: column 16 the SBP IMD code used to
 * route an IBFT, and column 66 the bank identifier shown against the payment.
 * Column 66 used to carry the full bank name and now carries the short code
 * (HBL, MCB, CITI).
 *
 * Asserted by reading the column out of the generated CSV rather than the row
 * array, because the position is the part the bank cares about and an array key
 * would still pass if the layout drifted.
 */
class BankFileBankCodeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TransactionTypeSeeder::class);
    }

    /** 1-indexed, per the iPayments template. */
    private const COL_BANK_CODE = 16;

    private const COL_BANK_IDENTIFIER = 66;

    private function bank(string $short, string $name, string $code): Bank
    {
        return Bank::firstOrCreate(
            ['bank_code' => $code],
            ['bank_name' => $name, 'bank_short_code' => $short],
        );
    }

    private function employee(string $email, ?Bank $bank): Employee
    {
        return Employee::create([
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => 'EMP-'.strtoupper(substr(md5($email), 0, 5)),
            'gender' => 'Male',
            'phone' => '0300-0000000',
            'bank_id' => $bank?->id,
            'iban_no' => 'PK24HABB0000001123456702',
            'bank_account_no' => '0908070605',
        ]);
    }

    /**
     * @return array<int, array<int, string>> data rows only, header and trailer dropped
     */
    private function dataRows(string $csv): array
    {
        return collect(explode("\n", trim($csv)))
            ->map(fn (string $line) => str_getcsv($line))
            ->filter(fn (array $cells) => ($cells[0] ?? '') === 'P')
            ->values()
            ->all();
    }

    private function cell(array $row, int $column): string
    {
        return trim((string) ($row[$column - 1] ?? ''));
    }

    public function test_the_salary_file_carries_the_short_code_not_the_bank_name(): void
    {
        $hbl = $this->bank('HBL', 'Habib Bank Limited', '600648');
        $employee = $this->employee('salary-code@test.local', $hbl);

        Payslip::create([
            'employee_id' => $employee->id,
            'month' => 'January',
            'fiscal_year_id' => $this->fiscalYear->id,
            'net_salary' => 100000,
        ]);

        $rows = $this->dataRows(app(SalaryBankExportService::class)->export('January', $this->fiscalYear));

        $this->assertCount(1, $rows);

        $this->assertSame('HBL', $this->cell($rows[0], self::COL_BANK_IDENTIFIER));
        $this->assertStringNotContainsStringIgnoringCase(
            'Habib Bank Limited',
            implode(',', $rows[0]),
            'The full bank name must not appear anywhere in the row.'
        );

        // The routing code is a different field and is untouched.
        $this->assertSame('600648', $this->cell($rows[0], self::COL_BANK_CODE));
    }

    public function test_the_payment_file_carries_the_short_code_not_the_bank_name(): void
    {
        $mcb = $this->bank('MCB', 'MCB Bank Limited', '627100');

        $beneficiary = Beneficiary::create([
            'name' => 'Skyline Internet',
            'bank_id' => $mcb->id,
            'iban' => 'PK24HABB0000001123456702',
            'account_no' => '5544332211',
            'payment_type' => 'IBFT',
        ]);

        $payment = Payment::create([
            'payable_type' => Beneficiary::class,
            'payable_id' => $beneficiary->id,
            'transaction_type_id' => TransactionType::query()->value('id'),
            'amount' => 25000,
            'details' => 'Internet — January',
            'value_date' => now()->toDateString(),
            'status' => Payment::STATUS_DRAFT,
        ]);

        $rows = $this->dataRows(app(BankPaymentExportService::class)->exportPayments(collect([$payment])));

        $this->assertCount(1, $rows);
        $this->assertSame('MCB', $this->cell($rows[0], self::COL_BANK_IDENTIFIER));
        $this->assertSame('627100', $this->cell($rows[0], self::COL_BANK_CODE));
        $this->assertStringNotContainsStringIgnoringCase('MCB Bank Limited', implode(',', $rows[0]));
    }

    /**
     * The accepted consequence of using the short code: it is nullable, and a bank
     * without one exports an empty column 66. The routing code in column 16 still
     * identifies the bank, so the payment is not misdirected — but the field is
     * blank, which is why the preview flags these rows before upload.
     */
    public function test_a_bank_with_no_short_code_exports_an_empty_identifier(): void
    {
        $noShortCode = Bank::firstOrCreate(
            ['bank_code' => '502841'],
            ['bank_name' => 'FINCA Microfinance Bank', 'bank_short_code' => null],
        );

        $employee = $this->employee('no-short-code@test.local', $noShortCode);

        Payslip::create([
            'employee_id' => $employee->id,
            'month' => 'February',
            'fiscal_year_id' => $this->fiscalYear->id,
            'net_salary' => 50000,
        ]);

        $rows = $this->dataRows(app(SalaryBankExportService::class)->export('February', $this->fiscalYear));

        $this->assertSame('', $this->cell($rows[0], self::COL_BANK_IDENTIFIER));
        $this->assertSame('502841', $this->cell($rows[0], self::COL_BANK_CODE), 'still routable');
    }

    public function test_the_preview_row_exposes_the_short_code_it_will_export(): void
    {
        // The on-screen preview reads this key; if it drifted from what the file
        // carries it would stop being a preview.
        $employee = $this->employee('preview@test.local', $this->bank('CITI', 'Citi Bank', '508117'));

        Payslip::create([
            'employee_id' => $employee->id,
            'month' => 'March',
            'fiscal_year_id' => $this->fiscalYear->id,
            'net_salary' => 75000,
        ]);

        $row = app(SalaryBankExportService::class)->paymentsForMonth('March', $this->fiscalYear)[0];

        $this->assertSame('CITI', $row['bank_short_code']);
        $this->assertSame('Citi Bank', $row['bank_name'], 'kept for display, no longer written to the file');
    }
}
