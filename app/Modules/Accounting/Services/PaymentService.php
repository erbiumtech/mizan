<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Support\PayrollAccounts;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\SalaryBankExportService;
use App\Support\ModuleMap;
use Illuminate\Support\Collection;
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
                    // The stable alias, not the live class: payable_type is one of
                    // the columns holding a class name across the module move.
                    'payable_type' => ModuleMap::alias(Employee::class),
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
     * Approve a draft payment and post its journal entry: debit what the money
     * was for, credit Cash/Bank (1100).
     *
     * Posted here and now, as petty cash and invoices do. The entry used to be
     * left as a draft, which put every payment ever approved outside the Profit &
     * Loss and the Trial Balance — those read posted entries only — and outside
     * the approval queue as well, since a draft has not been submitted to anyone.
     */
    public function approve(Payment $payment): Payment
    {
        if ($payment->status !== Payment::STATUS_DRAFT) {
            throw new InvalidArgumentException("Payment #{$payment->id} is not a draft.");
        }

        if (! $payment->journal_entry_id) {
            $payment->journal_entry_id = $this->postEntryFor($payment)?->id;
        }

        $payment->status = Payment::STATUS_APPROVED;
        $payment->save();

        return $payment;
    }

    /**
     * The payment's journal entry, posted. Null when there is nothing to record.
     */
    public function postEntryFor(Payment $payment): ?JournalEntry
    {
        // A payslip can come out at nothing — a month entirely unpaid leave, a
        // salary wholly absorbed by deductions — and payroll still raises the
        // payment. There is no entry to make for zero, and refusing it would stop
        // the whole batch that row happens to be in.
        if (round((float) $payment->amount, 2) <= 0) {
            return null;
        }

        $type = $payment->transactionType;
        $cashAccount = Account::where('code', '1100')->first();

        if (! $cashAccount) {
            throw new RuntimeException('Account 1100 Cash/Bank not found.');
        }

        $entry = $this->journalEntryService->create([
            'entry_date' => ($payment->value_date ?? now())->toDateString(),
            'entry_type' => 'general',
            'memo' => "{$type?->name} payment — {$payment->details}",
            'transaction_type_id' => $type?->id,
        ], [
            ['account_id' => $this->debitAccountFor($payment), 'debit_amount' => (float) $payment->amount, 'description' => $payment->details],
            ['account_id' => $cashAccount->id, 'credit_amount' => (float) $payment->amount, 'description' => $payment->details],
        ]);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $this->journalEntryService->post($entry);
    }

    /**
     * What the money came off.
     *
     * A payment that settles a payslip clears Salaries Payable, because the
     * payslip already booked the wage as an expense and credited that liability.
     * Debiting the salary expense account again — which is where the salary
     * transaction type points — would book the same wage twice and leave the
     * liability standing for ever.
     *
     * Everything else debits whatever its transaction type is for.
     */
    protected function debitAccountFor(Payment $payment): int
    {
        if ($payment->payslip_id) {
            return PayrollAccounts::id('salaries_payable');
        }

        $type = $payment->transactionType;

        // Refused rather than skipped. This silently booked nothing at all when
        // the type had no account — the payment went out, was marked approved, and
        // never reached the ledger.
        if (! $type?->account_id) {
            throw new RuntimeException(
                "Payment #{$payment->id} cannot be booked: the '".($type?->name ?? 'unknown')
                ."' transaction type has no account. Set one under Accounting → Transaction Types."
            );
        }

        return $type->account_id;
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

            // A draft on its way to the bank is approved first, so that money
            // leaving the company is in the books by definition. Releasing used to
            // stamp the status and nothing else, and a payment released without
            // being approved booked nothing at all — no entry, no expense, no trace
            // in the Profit & Loss, while the bank file went out regardless.
            if ($payment->status === Payment::STATUS_DRAFT) {
                $this->approve($payment);
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
     * Undo a release: the batch's payments go back in the pool and will appear in
     * the next one.
     *
     * For a file the bank rejected, or one built by mistake. Each payment goes
     * back to what it was before the release — see restoreToPool(). To put a
     * single row back without re-issuing the whole file, use revertExport().
     *
     * Refuses outright if any payment in the batch has been marked paid. That
     * says the money actually moved, and putting such a row back in the pool
     * would queue it to move again.
     *
     * @return \Illuminate\Support\Collection<int, Payment> the payments restored
     *
     * @throws InvalidArgumentException when the batch is unknown or partly paid
     */
    public function voidBatch(string $reference): Collection
    {
        $payments = Payment::inBatch($reference)->get();

        if ($payments->isEmpty()) {
            throw new InvalidArgumentException("No payments belong to batch {$reference}.");
        }

        $paid = $payments->where('status', Payment::STATUS_PAID);

        if ($paid->isNotEmpty()) {
            throw new InvalidArgumentException(
                "Batch {$reference} cannot be voided: {$paid->count()} of its payments are already marked paid, "
                .'so the money has moved. Reverse those individually instead.'
            );
        }

        foreach ($payments as $payment) {
            $this->restoreToPool($payment);
        }

        activity('Payment')
            ->withProperties([
                'batch_reference' => $reference,
                'payments' => $payments->pluck('id')->all(),
                'total' => round((float) $payments->sum('amount'), 2),
            ])
            ->log("Voided payment batch {$reference} — ".$payments->count().' payment(s) returned to the next batch');

        return $payments;
    }

    /**
     * Put a single exported payment back in the pool.
     *
     * The batch-level void is the usual way back, but a file is not always wrong
     * as a whole — one row bounces, or one payee's details were stale — and
     * re-issuing the entire batch to fix one payment is worse than fixing the one.
     *
     * @throws InvalidArgumentException when the payment never went out, or has
     *                                  been marked paid
     */
    public function revertExport(Payment $payment): Payment
    {
        if ($payment->status === Payment::STATUS_PAID) {
            throw new InvalidArgumentException(
                "Payment #{$payment->id} is marked paid, so the money has moved. Reverse it rather than "
                .'putting it back in the pool.'
            );
        }

        if ($payment->status !== Payment::STATUS_EXPORTED) {
            throw new InvalidArgumentException("Payment #{$payment->id} has not been exported.");
        }

        $batch = $payment->batch_reference;

        $this->restoreToPool($payment);

        activity('Payment')
            ->performedOn($payment)
            ->withProperties([
                'batch_reference' => $batch,
                'restored_to' => $payment->status,
                'amount' => (float) $payment->amount,
            ])
            ->log("Payment #{$payment->id} reverted from exported"
                .($batch ? " (was in batch {$batch})" : '').' and returned to the next batch');

        return $payment;
    }

    /**
     * Back to whatever it was before the release, and out of its batch.
     *
     * The prior status is reconstructed rather than stored, and exactly: approve()
     * is the only thing that ever sets journal_entry_id on a payment, and it sets
     * the status to approved at the same time — so a released payment carrying a
     * journal entry was approved before it went, and one without was a draft.
     */
    protected function restoreToPool(Payment $payment): void
    {
        $payment->update([
            'status' => $payment->journal_entry_id ? Payment::STATUS_APPROVED : Payment::STATUS_DRAFT,
            'batch_reference' => null,
            'released_at' => null,
        ]);
    }

    /**
     * Batches that could still be voided, newest first: those whose payments are
     * exported and none of them paid.
     *
     * @return \Illuminate\Support\Collection<string, string> reference => label
     */
    public function voidableBatches(int $limit = 20): Collection
    {
        return Payment::query()
            ->whereNotNull('batch_reference')
            ->where('status', Payment::STATUS_EXPORTED)
            ->get()
            ->groupBy('batch_reference')
            ->sortByDesc(fn (Collection $payments) => $payments->max('released_at'))
            ->take($limit)
            ->map(function (Collection $payments, string $reference): string {
                $released = $payments->max('released_at');

                return sprintf(
                    '%s — %d payment(s), %s%s',
                    $reference,
                    $payments->count(),
                    number_format((float) $payments->sum('amount'), 2),
                    $released ? ', released '.$released->format('d M Y H:i') : '',
                );
            });
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
