<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Payment;
use App\Models\Payslip;
use App\Support\BankFileAccount;
use Carbon\Carbon;

/**
 * Builds a Standard Chartered iPayments bulk-payment CSV (UTF-8,
 * comma-delimited, 204 columns per row) from one month's payslips.
 * Layout per docs/ipayments_csv (002).csv: an H header row, one P row
 * per salary payment, and a T trailer with record count and total.
 */
class SalaryBankExportService
{
    public const COLUMNS = 204;

    // 1-indexed column positions from the template's label row.
    protected const COL = [
        'record_type' => 1,
        'payment_type' => 2,
        'processing_mode' => 3,
        'customer_reference' => 5,
        'debit_country' => 7,
        'debit_city' => 8,
        'debit_account' => 9,
        'value_date' => 10,
        'beneficiary_name' => 11,
        'payee_address_1' => 12,
        'payee_address_2' => 13,
        'payee_country' => 14,
        'beneficiary_bank_code' => 16,
        'beneficiary_account' => 20,
        'payment_details_1' => 21,
        'payment_details_2' => 22,
        'invoice_format' => 37,
        'payment_currency' => 38,
        'amount' => 39,
        'debit_currency' => 60,
        'debit_bank_id' => 61,
        'beneficiary_email' => 63,
        'beneficiary_bank_name' => 66,
        'purpose_of_payment' => 166,
        'beneficiary_id' => 167,
        'beneficiary_id_type' => 168,
        'beneficiary_contact' => 204,
    ];

    /**
     * The payslips that would be exported for a month, with payment rows.
     */
    /**
     * @param  array<int, int>|null  $payslipIds  restrict to these payslips; null means the whole month
     */
    public function paymentsForMonth(string $month, FiscalYear $fiscalYear, ?array $payslipIds = null): array
    {
        $payslips = Payslip::with(['employee.user', 'employee.bank'])
            ->where('month', $month)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->when($payslipIds !== null, fn ($q) => $q->whereKey($payslipIds))
            ->get()
            ->sortBy(fn ($p) => $p->employee->user->name ?? '')
            ->values();

        $year = $this->yearForMonth($month, $fiscalYear);

        return $payslips->map(function (Payslip $p) use ($month, $year) {
            $employee = $p->employee;

            $account = BankFileAccount::resolve(
                $employee->iban_no,
                $employee->bank_account_no,
                $employee->bank,
                $employee->bank_short_code,
                $employee->bank_name ?? null,
            );

            return [
                'payslip' => $p,
                'payslip_id' => $p->id,
                'employee_code' => $employee->employee_id,
                'name' => $employee->user->name ?? $employee->employee_id,
                // SCB beneficiaries go by account number (intra-bank); everyone
                // else by IBAN (inter-bank IBFT). See BankFileAccount.
                'account' => $account['value'],
                'account_kind' => $account['kind'],
                'bank_code' => $employee->bank?->bank_code ?? $employee->bank_code ?? '',
                'bank_name' => $employee->bank?->bank_name ?? $employee->bank_name ?? '',
                'bank_short_code' => $employee->bank?->bank_short_code ?? $employee->bank_short_code ?? '',
                'address_1' => $employee->address_line_1 ?? '',
                'address_2' => $employee->address_line_2 ?? '',
                'email' => $employee->user->email ?? '',
                'nic' => $employee->nic ?? '',
                'phone' => $employee->phone ?? '',
                'amount' => (float) $p->net_salary,
                'details' => "Salary {$month} {$year}",
            ];
        })->all();
    }

    /**
     * Full CSV file content for a month's salaries.
     */
    /**
     * @param  array<int, int>|null  $payslipIds  restrict to these payslips; null means the whole month
     */
    public function export(string $month, FiscalYear $fiscalYear, ?string $valueDate = null, ?array $payslipIds = null): string
    {
        $payments = $this->paymentsForMonth($month, $fiscalYear, $payslipIds);
        $valueDate = $valueDate
            ? Carbon::parse($valueDate)->format('d/m/Y')
            : now()->format('d/m/Y');

        $config = setting('ipayments');

        $rows = [];

        $rows[] = $this->row(['record_type' => 'H', 'payment_type' => 'P']);

        $total = 0.0;

        foreach ($payments as $i => $payment) {
            $total += $payment['amount'];

            $rows[] = $this->row([
                'record_type' => 'P',
                // Every row here is a salary transfer, but the bank still
                // requires RTGS above the threshold — same precedence as
                // Payment::resolvedPaymentType, so the two files agree.
                'payment_type' => $payment['amount'] >= Payment::RTGS_THRESHOLD
                    ? 'RTGS'
                    : ($config['salary_payment_type'] ?? 'PAY'),
                'processing_mode' => $config['processing_mode'],
                'customer_reference' => sprintf('SAL-%s-%03d', strtoupper(substr($month, 0, 3)), $i + 1),
                'debit_country' => $config['debit_country'],
                'debit_city' => $config['debit_city'],
                'debit_account' => $config['debit_account'],
                'value_date' => $valueDate,
                'beneficiary_name' => $payment['name'],
                'payee_address_1' => $payment['address_1'],
                'payee_address_2' => $payment['address_2'],
                'payee_country' => $config['debit_country'],
                'beneficiary_bank_code' => $payment['bank_code'],
                'beneficiary_account' => $payment['account'],
                'payment_details_1' => $payment['details'],
                'invoice_format' => $config['invoice_format'],
                'payment_currency' => $config['currency'],
                'amount' => $this->formatAmount($payment['amount']),
                'debit_currency' => $config['currency'],
                'debit_bank_id' => $config['debit_bank_id'],
                'beneficiary_email' => $payment['email'],
                'beneficiary_bank_name' => $payment['bank_short_code'],
                'purpose_of_payment' => $config['purpose_of_payment'],
                'beneficiary_id' => $payment['nic'],
                'beneficiary_id_type' => $payment['nic'] ? 'CNIC' : '',
                'beneficiary_contact' => $payment['phone'],
            ]);
        }

        $rows[] = $this->row([
            'record_type' => 'T',
            'payment_type' => (string) count($payments),
            'processing_mode' => $this->formatAmount($total),
        ]);

        return implode("\r\n", $rows)."\r\n";
    }

    public function fileName(string $month, FiscalYear $fiscalYear): string
    {
        return sprintf('salary-ipayments-%s-%d.csv', $month, $this->yearForMonth($month, $fiscalYear));
    }

    /**
     * Calendar year a payslip month falls in within the fiscal year.
     */
    public function yearForMonth(string $month, FiscalYear $fiscalYear): int
    {
        $startYear = Carbon::parse($fiscalYear->start_date)->year;
        $monthNumber = Carbon::parse("{$month} 1, {$startYear}")->month;

        return $monthNumber >= Carbon::parse($fiscalYear->start_date)->month
            ? $startYear
            : Carbon::parse($fiscalYear->end_date)->year;
    }

    /**
     * One 204-column CSV row from a map of column-name => value.
     */
    protected function row(array $values): string
    {
        $cells = array_fill(0, self::COLUMNS, '');

        foreach ($values as $key => $value) {
            $cells[self::COL[$key] - 1] = $this->escape((string) $value);
        }

        return implode(',', $cells);
    }

    protected function formatAmount(float $amount): string
    {
        $formatted = number_format($amount, 2, '.', '');

        return str_ends_with($formatted, '.00') ? substr($formatted, 0, -3) : $formatted;
    }

    /**
     * iPayments files are plain comma-delimited; strip characters that
     * would break the layout rather than quoting them.
     */
    protected function escape(string $value): string
    {
        return trim(str_replace([',', '"', "\r", "\n"], ' ', $value));
    }
}
