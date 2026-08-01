<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\Payment;
use Illuminate\Support\Collection;
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
            $payment = Payment::firstOrNew(['payslip_id' => $payslip->id]);

            // Status, batch reference and released_at are deliberately absent
            // here. This runs every time either bank-file page is opened, and an
            // updateOrCreate that included status put every released payment back
            // to draft on the next page view — so "exported" never stuck and a
            // salary already sent to the bank reappeared in the following batch.
            //
            // A released payment is a record of what was actually sent, so its
            // figures are left alone too: restating the amount afterwards would
            // make the row disagree with the file the bank received.
            if (! $payment->isReleased()) {
                $payment->fill([
                    'payable_type' => Employee::class,
                    'payable_id' => $payslip->employee_id,
                    'transaction_type_id' => $type->id,
                    'company_bank_account_id' => $defaultAccount?->id,
                    'amount' => $payslip->net_salary,
                    'details' => "Salary {$month} {$year}",
                ]);
            }

            if (! $payment->exists) {
                $payment->status = Payment::STATUS_DRAFT;
                $created++;
            }

            $payment->save();
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

    /**
     * Send a set of payments out as one batch.
     *
     * Stamps every payment with the same reference and marks it exported, which
     * is what keeps it out of the next batch — a month's salaries can leave in
     * several files as employees accept their payslips, and nothing may go twice.
     *
     * Payments that are not releasable are skipped rather than refused: the
     * caller has already filtered, and failing the whole batch because one row
     * changed underneath would leave the operator with no way forward. Returns
     * the payments that actually went.
     *
     * @param  iterable<Payment>  $payments
     * @return \Illuminate\Support\Collection<int, Payment>
     */
    public function release(iterable $payments, string $reference): Collection
    {
        $released = collect();
        $now = now();

        foreach ($payments as $payment) {
            if (! $payment->isReleasable()) {
                continue;
            }

            $payment->update([
                'status' => Payment::STATUS_EXPORTED,
                'batch_reference' => $reference,
                'released_at' => $now,
            ]);

            $released->push($payment);
        }

        return $released;
    }

    /**
     * The next unused reference for a prefix, e.g. SAL-2026-07 -> SAL-2026-07-B2
     * when B1 has already gone.
     *
     * Numbered from what is stored rather than a counter, so it stays correct if
     * a batch is voided or the table is restored from a backup.
     */
    public function nextBatchReference(string $prefix): string
    {
        $used = Payment::query()
            ->where('batch_reference', 'like', $prefix.'-B%')
            ->pluck('batch_reference')
            ->map(fn (string $reference): int => (int) str($reference)->afterLast('-B')->toString())
            ->filter()
            ->max() ?? 0;

        return $prefix.'-B'.($used + 1);
    }
}
