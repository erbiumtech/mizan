<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Cheque;
use App\Modules\Accounting\Models\ChequeBook;
use App\Modules\Accounting\Models\ChequeLayout;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Support\AmountWords;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * Cheques, from an approved payment to a cleared one — docs/saas-launch-plan.md §8.6.
 *
 * print() takes the next number from the account's book, writes the cheque as
 * printed, and releases the payment with the number as its reference — the
 * same `exported` the bank file gives, which is why the bank file leaves
 * cheque payments alone. void() puts the payment back in the pool and the
 * number stays burnt; reprint() is void() then print(). clear() is what the
 * reconciliation calls when the statement line arrives: the cheque is cleared
 * and the payment is paid.
 */
class ChequePrinter
{
    public const PAYMENT_TYPE = 'cheque';

    public function __construct(private readonly PaymentService $payments) {}

    public function print(Payment $payment, ?User $by = null, bool $crossed = true): Cheque
    {
        if (Cheque::query()->where('payment_id', $payment->getKey())->outstanding()->exists()) {
            throw new InvalidArgumentException("Payment #{$payment->id} already has a cheque out. Void it before printing another.");
        }

        if (! in_array($payment->status, [Payment::STATUS_DRAFT, Payment::STATUS_APPROVED], true)) {
            throw new InvalidArgumentException("Payment #{$payment->id} is {$payment->status}; only a draft or approved payment prints.");
        }

        if (! $payment->company_bank_account_id) {
            throw new InvalidArgumentException('Pick the bank account the cheque is drawn on first.');
        }

        $payee = $this->payeeName($payment);

        if ($payee === '') {
            throw new InvalidArgumentException('The payment has no payee to write on the cheque.');
        }

        return TenantTransaction::run(function () use ($payment, $by, $crossed, $payee): Cheque {
            $book = ChequeBook::query()
                ->active()
                ->where('company_bank_account_id', $payment->company_bank_account_id)
                ->whereColumn('next_no', '<=', 'last_no')
                ->orderBy('first_no')
                ->lockForUpdate()
                ->first();

            if ($book === null) {
                throw new InvalidArgumentException('No cheque book with numbers left for this account. Add one under Banking → Cheque Books.');
            }

            $number = $book->format($book->next_no);
            $book->update(['next_no' => $book->next_no + 1]);

            if ($payment->status === Payment::STATUS_DRAFT) {
                $this->payments->approve($payment);
            }

            $cheque = Cheque::create([
                'cheque_book_id' => $book->getKey(),
                'company_bank_account_id' => $payment->company_bank_account_id,
                'cheque_no' => $number,
                'payment_id' => $payment->getKey(),
                'payee_name' => $payee,
                'amount' => $payment->amount,
                'cheque_date' => $payment->value_date ?? now()->toDateString(),
                'crossed' => $crossed,
                'status' => Cheque::STATUS_PRINTED,
                'printed_at' => now(),
                'printed_by' => $by?->getKey(),
            ]);

            // Released: the number is the reference, and the bank file never sees it.
            $payment->update([
                'payment_type' => self::PAYMENT_TYPE,
                'status' => Payment::STATUS_EXPORTED,
                'batch_reference' => 'CHQ '.$number,
                'released_at' => now(),
            ]);

            activity('Payment')
                ->performedOn($payment)
                ->causedBy($by)
                ->withProperties(['cheque_no' => $number, 'amount' => (float) $payment->amount])
                ->log("Cheque {$number} printed for payment #{$payment->id}");

            return $cheque;
        });
    }

    /** The number stays burnt; the payment goes back to the pool unless it was paid. */
    public function void(Cheque $cheque, string $reason, ?User $by = null): Cheque
    {
        if (! $cheque->isOutstanding()) {
            throw new InvalidArgumentException("Cheque {$cheque->cheque_no} is {$cheque->status} and cannot be voided.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A voided cheque carries the reason it was voided.');
        }

        TenantTransaction::run(function () use ($cheque, $reason, $by): void {
            $cheque->update(['status' => Cheque::STATUS_VOID, 'void_reason' => $reason]);

            if ($cheque->payment && $cheque->payment->status === Payment::STATUS_EXPORTED) {
                $this->payments->revertExport($cheque->payment);
                $cheque->payment->update(['payment_type' => self::PAYMENT_TYPE]);
            }

            activity('Payment')
                ->performedOn($cheque->payment ?? $cheque)
                ->causedBy($by)
                ->withProperties(['cheque_no' => $cheque->cheque_no, 'reason' => $reason])
                ->log("Cheque {$cheque->cheque_no} voided: {$reason}");
        });

        return $cheque->fresh();
    }

    public function reprint(Cheque $cheque, string $reason, ?User $by = null): Cheque
    {
        $payment = $cheque->payment;

        if ($payment === null) {
            throw new InvalidArgumentException("Cheque {$cheque->cheque_no} has no payment to reprint for.");
        }

        $this->void($cheque, $reason, $by);

        return $this->print($payment->fresh(), $by, $cheque->crossed);
    }

    /** The statement line arrived: the cheque is cleared and the money has moved. */
    public function clear(Cheque $cheque): Cheque
    {
        if (! $cheque->isOutstanding()) {
            return $cheque;
        }

        $cheque->update(['status' => Cheque::STATUS_CLEARED, 'cleared_at' => now()]);
        $cheque->payment?->update(['status' => Payment::STATUS_PAID]);

        return $cheque->fresh();
    }

    public function renderPdf(Cheque $cheque): PdfDocument
    {
        $layout = ChequeLayout::for($cheque->company_bank_account_id);

        return $this->document($layout, [
            'payee' => $cheque->payee_name,
            'amount_words' => AmountWords::write((float) $cheque->amount),
            'amount_figures' => '**'.number_format((float) $cheque->amount, 2).'**',
            'date' => $cheque->cheque_date->format('d-m-Y'),
            'crossing' => $cheque->crossed ? 'A/C PAYEE ONLY' : '',
        ], 'cheque-'.$cheque->cheque_no.'.pdf');
    }

    /** The field names at their positions, to hold against a real leaf. */
    public function testPrint(ChequeLayout $layout): PdfDocument
    {
        return $this->document($layout, [
            'payee' => '[PAYEE NAME]',
            'amount_words' => '[AMOUNT IN WORDS — '.AmountWords::write(1250000.5).']',
            'amount_figures' => '[**12,50,000.50**]',
            'date' => '[DD-MM-YYYY]',
            'crossing' => '[A/C PAYEE ONLY]',
        ], 'cheque-layout-test.pdf');
    }

    public function payeeName(Payment $payment): string
    {
        $payee = $payment->payee();

        return trim((string) ($payee instanceof Employee ? $payee->fullName() : ($payee->name ?? '')));
    }

    /** @param  array<string, string>  $values */
    private function document(ChequeLayout $layout, array $values, string $name): PdfDocument
    {
        return Pdf::view('pdfs.cheque', ['layout' => $layout, 'values' => $values])
            ->paperSize((float) $layout->page_width_mm, (float) $layout->page_height_mm)
            ->margins(0, 0, 0, 0)
            ->name($name);
    }
}
