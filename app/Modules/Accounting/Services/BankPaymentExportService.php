<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Employees\Models\Employee;
use App\Support\Banking\IPaymentsFileWriter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * One iPayments file from any set of Payment rows — salaries, rent, food — with the Payment Type column
 * resolved per transaction and the debit account taken from each payment's own company bank account.
 *
 * **This class used to `extend Payroll\Services\SalaryBankExportService`, and that was the single
 * import in this codebase that could not be guarded.** A licence check can wrap a call and a container
 * binding can be swapped, but an `extends` across a module boundary can do neither: Accounting simply
 * could not load without Payroll on disk. What it actually needed from the parent was three methods
 * describing a *file layout* — `row()`, `formatAmount()` and the column map behind them — so those moved
 * to `App\Support\Banking\IPaymentsFileWriter`, which neither module owns. See
 * docs/module-packaging-plan.md §7.
 *
 * Composition rather than inheritance also removed something misleading: this class inherited
 * `export()`, `paymentsForMonth()` and `fileName()`, none of which it wanted and all of which were
 * salary-specific. Nothing called them on it, but they were part of its public surface.
 */
class BankPaymentExportService
{
    public function __construct(private readonly IPaymentsFileWriter $file) {}

    /**
     * @param  Collection<int, Payment>  $payments
     */
    public function exportPayments(Collection $payments, ?string $valueDate = null): string
    {
        $valueDate = $valueDate
            ? Carbon::parse($valueDate)->format('d/m/Y')
            : now()->format('d/m/Y');

        $config = setting('ipayments');

        $rows = [$this->file->row(['record_type' => 'H', 'payment_type' => 'P'])];
        $total = 0.0;

        /*
         * Everything this file reads per row, loaded for the whole set first.
         *
         * The loop below asks each payment for its debit account and, through beneficiaryDetails(),
         * for the employee or beneficiary behind it and their bank — four relations, once per row, on
         * a set that is a whole month of salaries. Loaded here it is four queries whatever the size.
         *
         * Rebuilt as an Eloquent collection because the parameter is a plain Support collection,
         * which has no loadMissing: callers hand this whatever they happened to have.
         */
        $payments = EloquentCollection::make($payments->values()->all());

        // `payable` in this first call, and it has to be: loadMorph() below starts by plucking the
        // relation off every model to group them by class, which is itself a lazy read — so calling it
        // on an unloaded morph throws the very violation it is here to avoid.
        // `withholdingDeduction` for transferAmount() below — one query for the set rather than one per row,
        // and null for every payment that has no section assigned, which is almost all of them.
        $payments->loadMissing(['companyBankAccount', 'payslip', 'payable', 'withholdingDeduction']);
        $payments->loadMorph('payable', [
            Employee::class => ['bank', 'user'],
            Beneficiary::class => ['bank'],
        ]);

        foreach ($payments as $i => $payment) {
            $beneficiary = $payment->beneficiaryDetails();
            $debit = $payment->companyBankAccount;
            // The net, which for everything without a withholding deduction is the amount — see
            // Payment::transferAmount(). The trailer total and the row are the same figure by construction:
            // a file whose trailer disagreed with its rows is rejected by the bank.
            $transfer = $payment->transferAmount();
            $total += $transfer;

            $rows[] = $this->file->row([
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
                'amount' => $this->file->formatAmount($transfer),
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

        $rows[] = $this->file->row([
            'record_type' => 'T',
            'payment_type' => (string) $payments->count(),
            'processing_mode' => $this->file->formatAmount($total),
        ]);

        return implode("\r\n", $rows)."\r\n";
    }

    public function paymentFileName(?string $typeName = null): string
    {
        $slug = $typeName ? strtolower(str_replace(' ', '-', $typeName)) : 'all';

        return sprintf('bank-payments-%s-%s.csv', $slug, now()->format('Y-m-d'));
    }
}
