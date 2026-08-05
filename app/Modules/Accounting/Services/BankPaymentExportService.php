<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Payroll\Services\SalaryBankExportService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Generalizes SalaryBankExportService: builds one iPayments file from any
 * set of Payment rows (salaries, rent, food…). The Payment Type column is
 * resolved per transaction and the debit account comes from each payment's
 * company bank account.
 */
class BankPaymentExportService extends SalaryBankExportService
{
    /**
     * @param  Collection<int, Payment>  $payments
     */
    public function exportPayments(Collection $payments, ?string $valueDate = null): string
    {
        $valueDate = $valueDate
            ? Carbon::parse($valueDate)->format('d/m/Y')
            : now()->format('d/m/Y');

        $config = setting('ipayments');

        $rows = [$this->row(['record_type' => 'H', 'payment_type' => 'P'])];
        $total = 0.0;

        foreach ($payments->values() as $i => $payment) {
            $beneficiary = $payment->beneficiaryDetails();
            $debit = $payment->companyBankAccount;
            $total += (float) $payment->amount;

            $rows[] = $this->row([
                'record_type' => 'P',
                'payment_type' => $payment->resolvedPaymentType(),
                'processing_mode' => $config['processing_mode'],
                'customer_reference' => $payment->reference ?: sprintf('PMT-%06d', $payment->id),
                'debit_country' => $config['debit_country'],
                'debit_city' => $config['debit_city'],
                'debit_account' => $debit?->account_no ?: $config['debit_account'],
                'value_date' => $payment->value_date?->format('d/m/Y') ?? $valueDate,
                'beneficiary_name' => $beneficiary['name'],
                'payee_address_1' => $beneficiary['address_1'],
                'payee_address_2' => $beneficiary['address_2'],
                'payee_country' => $config['debit_country'],
                'beneficiary_bank_code' => $beneficiary['bank_code'],
                'beneficiary_account' => $beneficiary['account'],
                'payment_details_1' => $payment->details,
                'invoice_format' => $config['invoice_format'],
                'payment_currency' => $config['currency'],
                // `amount` — a Payment has no net_salary column, so reading one
                // gave null and every row exported 0.00 while the trailer total
                // (built from ->amount just above) stayed correct. For a salary
                // this column already carries the payslip's net figure:
                // generateSalaryPayments() copies it in.
                'amount' => $this->formatAmount((float) $payment->amount),
                'debit_currency' => $config['currency'],
                'debit_bank_id' => $config['debit_bank_id'],
                'beneficiary_email' => $beneficiary['email'],
                'beneficiary_bank_name' => $beneficiary['bank_short_code'],
                'purpose_of_payment' => $config['purpose_of_payment'],
                'beneficiary_id' => $beneficiary['id_number'],
                'beneficiary_id_type' => $beneficiary['id_type'],
                'beneficiary_contact' => $beneficiary['phone'],
            ]);
        }

        $rows[] = $this->row([
            'record_type' => 'T',
            'payment_type' => (string) $payments->count(),
            'processing_mode' => $this->formatAmount($total),
        ]);

        return implode("\r\n", $rows)."\r\n";
    }

    public function paymentFileName(?string $typeName = null): string
    {
        $slug = $typeName ? strtolower(str_replace(' ', '-', $typeName)) : 'all';

        return sprintf('bank-payments-%s-%s.csv', $slug, now()->format('Y-m-d'));
    }
}
