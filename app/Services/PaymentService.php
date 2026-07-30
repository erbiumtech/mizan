<?php

namespace App\Services;

use App\Support\ModuleMap;
use App\Models\Account;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\Payment;
use App\Models\Payslip;
use App\Models\TransactionType;
use InvalidArgumentException;
use RuntimeException;

class PaymentService
{
    public function __construct(private JournalEntryService $journalEntryService)
    {
    }

    /**
     * One draft salary Payment per payslip of the month (idempotent via
     * the unique payslip_id).
     */
    public function generateSalaryPayments(string $month, FiscalYear $fiscalYear): int
    {
        $type = TransactionType::byCode('salary');

        if (! $type) {
            throw new RuntimeException('Salary transaction type not found. Run TransactionTypeSeeder.');
        }

        $defaultAccount = $type->defaultCompanyBankAccount();
        $year = app(SalaryBankExportService::class)->yearForMonth($month, $fiscalYear);
        $created = 0;

        $payslips = Payslip::with('employee')
            ->where('month', $month)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->get();

        foreach ($payslips as $payslip) {
            $payment = Payment::firstOrCreate(
                ['payslip_id' => $payslip->id],
                [
                    'payable_type' => ModuleMap::alias(Employee::class),
                    'payable_id' => $payslip->employee_id,
                    'transaction_type_id' => $type->id,
                    'company_bank_account_id' => $defaultAccount?->id,
                    'amount' => $payslip->net_salary,
                    'details' => "Salary {$month} {$year}",
                    'status' => Payment::STATUS_DRAFT,
                ]
            );

            if ($payment->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Approve a draft payment and book its journal entry:
     * debit the transaction type's account, credit Cash/Bank (1100).
     */
    public function approve(Payment $payment): Payment
    {
        if ($payment->status !== Payment::STATUS_DRAFT) {
            throw new InvalidArgumentException("Payment #{$payment->id} is not a draft.");
        }

        $type = $payment->transactionType;

        if (! $payment->journal_entry_id && $type?->account_id) {
            $cashAccount = Account::where('code', '1100')->first();

            if (! $cashAccount) {
                throw new RuntimeException('Account 1100 Cash/Bank not found.');
            }

            $entry = $this->journalEntryService->create([
                'entry_date' => ($payment->value_date ?? now())->toDateString(),
                'entry_type' => 'general',
                'memo' => "{$type->name} payment — {$payment->details}",
                'transaction_type_id' => $type->id,
            ], [
                ['account_id' => $type->account_id, 'debit_amount' => (float) $payment->amount, 'description' => $payment->details],
                ['account_id' => $cashAccount->id, 'credit_amount' => (float) $payment->amount, 'description' => $payment->details],
            ]);

            $payment->journal_entry_id = $entry->id;
        }

        $payment->status = Payment::STATUS_APPROVED;
        $payment->save();

        return $payment;
    }

    public function markExported(iterable $payments): void
    {
        foreach ($payments as $payment) {
            if (in_array($payment->status, [Payment::STATUS_DRAFT, Payment::STATUS_APPROVED], true)) {
                $payment->update(['status' => Payment::STATUS_EXPORTED]);
            }
        }
    }
}
