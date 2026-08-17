<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Support\BankFileAccount;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Banking\IPaymentsFileWriter;
use App\Support\PayrollMonth;
use Carbon\Carbon;

/**
 * Builds a Standard Chartered iPayments bulk-payment CSV (UTF-8,
 * comma-delimited, 204 columns per row) from one month's payslips.
 * Layout per docs/ipayments_csv (002).csv: an H header row, one P row
 * per salary payment, and a T trailer with record count and total.
 */
class SalaryBankExportService
{
    /**
     * The layout moved to App\Support\Banking\IPaymentsFileWriter — it is a bank's file format rather
     * than payroll, and keeping it here forced Accounting to *extend* this class to reuse it. See
     * docs/module-packaging-plan.md §7. Kept as an alias because callers reference it.
     */
    public const COLUMNS = IPaymentsFileWriter::COLUMNS;

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
                'account_problem' => $account['problem'],
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
    /**
     * Kept as a passthrough. The arithmetic moved to App\Support\PayrollMonth so that Accounting
     * could stop importing this service to label a file; this signature is public and widely called, so
     * removing it would break callers to move four lines.
     */
    public function yearForMonth(string $month, FiscalYear $fiscalYear): int
    {
        return PayrollMonth::yearFor($month, $fiscalYear);
    }

    /**
     * The file format, shared with Accounting's payment export rather than inherited by it.
     *
     * These three stay as thin protected methods so this class reads the way it always did; what changed
     * is that the layout is no longer *here*, so nothing has to extend this class to use it.
     *
     * @param  array<string, mixed>  $values
     */
    protected function row(array $values): string
    {
        return $this->file()->row($values);
    }

    protected function formatAmount(float $amount): string
    {
        return $this->file()->formatAmount($amount);
    }

    protected function escape(string $value): string
    {
        return $this->file()->escape($value);
    }

    protected function file(): IPaymentsFileWriter
    {
        return app(IPaymentsFileWriter::class);
    }
}
