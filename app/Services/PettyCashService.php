<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Beneficiary;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PettyCashVoucher;
use App\Models\TransactionType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Imprest petty cash book: vouchers analyzed by transaction type, month
 * closed with a c/d balance, float restored by a replenishment Payment to
 * the custodian beneficiary through the bank payment file.
 */
class PettyCashService
{
    public function __construct(private RegisterEntryService $register) {}

    public function account(): Account
    {
        return Account::where('code', '1150')->firstOr(function () {
            throw new RuntimeException('Account 1150 Petty Cash not found. Run ChartOfAccountsSeeder.');
        });
    }

    public function floatAmount(): float
    {
        return (float) setting('petty_cash.float_amount');
    }

    public function balanceAsOf(?string $date = null): float
    {
        $account = $this->account();

        $query = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($date) {
                $q->where('is_posted', true);
                if ($date) {
                    $q->whereDate('entry_date', '<=', $date);
                }
            });

        return round((float) (clone $query)->sum('debit_amount') - (float) $query->sum('credit_amount'), 2);
    }

    /**
     * Fund the box from the bank: debit 1150, credit 1100.
     */
    public function topUp(string $date, float $amount, string $details = 'Cash'): void
    {
        $bank = Account::where('code', '1100')->firstOrFail();

        $this->register->bookRow($this->account(), $bank, [
            'date' => $date,
            'description' => $details,
            'direction' => 'in',
            'amount' => $amount,
        ]);
    }

    /**
     * One Paid-side row: books debit expense / credit 1150 and records the
     * voucher. Rejects overdrawing the float or booking into a replenished month.
     */
    public function bookVoucher(array $data): PettyCashVoucher
    {
        $type = TransactionType::findOrFail($data['transaction_type_id']);

        if (! $type->account_id) {
            throw new InvalidArgumentException("Transaction type {$type->name} has no default account.");
        }

        $date = Carbon::parse($data['date']);

        if ($this->isMonthReplenished($date)) {
            throw new InvalidArgumentException($date->format('F Y').' is closed — it has already been replenished.');
        }

        $amount = round((float) $data['amount'], 2);

        if ($amount > $this->balanceAsOf()) {
            throw new InvalidArgumentException('Petty cash float would be overdrawn (balance: '.number_format($this->balanceAsOf(), 2).').');
        }

        $entry = $this->register->bookRow($this->account(), $type->account, [
            'date' => $date->toDateString(),
            'description' => $data['details'],
            'direction' => 'out',
            'amount' => $amount,
        ]);

        return PettyCashVoucher::create([
            'date' => $date->toDateString(),
            'details' => $data['details'],
            'amount' => $amount,
            'transaction_type_id' => $type->id,
            'receipt_path' => $data['receipt_path'] ?? null,
            'journal_entry_id' => $entry->id,
        ]);
    }

    /**
     * Correct a voucher's details/amount (and receipt) while its month is still
     * open. The posted 2-line entry is adjusted in place rather than reversed:
     * a reversal would be dated today and surface as a bogus Received row in
     * the book. Balances are moved by the delta, mirroring JournalEntryService::post().
     */
    public function updateVoucher(PettyCashVoucher $voucher, array $data): PettyCashVoucher
    {
        if ($this->isMonthReplenished($voucher->date)) {
            throw new InvalidArgumentException($voucher->date->format('F Y').' is closed — it has already been replenished.');
        }

        $details = trim((string) $data['details']);
        $amount = round((float) $data['amount'], 2);

        if ($details === '') {
            throw new InvalidArgumentException('Details are required.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        $delta = round($amount - (float) $voucher->amount, 2);

        if ($delta > $this->balanceAsOf()) {
            throw new InvalidArgumentException('Petty cash float would be overdrawn (balance: '.number_format($this->balanceAsOf(), 2).').');
        }

        $entry = $voucher->journalEntry;
        $lines = $entry?->lines()->get() ?? collect();

        if ($entry) {
            if ($lines->count() !== 2) {
                throw new InvalidArgumentException("Entry {$entry->entry_number} is a split — edit it in the journal instead.");
            }

            if ($lines->contains(fn (JournalEntryLine $line) => $line->reconciled_at !== null)) {
                throw new InvalidArgumentException("Entry {$entry->entry_number} is reconciled and can no longer be changed.");
            }
        }

        DB::transaction(function () use ($voucher, $entry, $lines, $details, $amount, $delta, $data): void {
            foreach ($lines as $line) {
                $isDebit = (float) $line->debit_amount > 0;

                if ($delta !== 0.0) {
                    $account = Account::lockForUpdate()->find($line->account_id);
                    $sign = ($account->normal_balance === 'debit' ? 1 : -1) * ($isDebit ? 1 : -1);

                    $account->balance = (float) $account->balance + ($sign * $delta);
                    $account->save();
                }

                $line->update([
                    'debit_amount' => $isDebit ? $amount : 0,
                    'credit_amount' => $isDebit ? 0 : $amount,
                    'description' => $details,
                ]);
            }

            $entry?->update(['memo' => $details]);

            $voucher->update([
                'details' => $details,
                'amount' => $amount,
                'receipt_path' => array_key_exists('receipt_path', $data)
                    ? ($data['receipt_path'] ?: null)
                    : $voucher->receipt_path,
            ]);
        });

        if ($entry) {
            activity('PettyCashVoucher')
                ->performedOn($voucher)
                ->event('adjusted')
                ->withProperties([
                    'voucher_no' => $voucher->voucher_no,
                    'entry_number' => $entry->entry_number,
                    'amount_delta' => $delta,
                ])
                ->log("Voucher {$voucher->voucher_no} adjusted; entry {$entry->entry_number} restated to ".number_format($amount, 2));
        }

        return $voucher->refresh();
    }

    /**
     * The two-sided book for one month.
     */
    public function monthSummary(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $account = $this->account();

        $opening = $this->balanceAsOf($start->copy()->subDay()->toDateString());

        // Received side: debit lines on 1150 in the month (top-ups/replenishments).
        $received = JournalEntryLine::where('account_id', $account->id)
            ->where('debit_amount', '>', 0)
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true)
                ->whereDate('entry_date', '>=', $start->toDateString())
                ->whereDate('entry_date', '<=', $end->toDateString()))
            ->with('journalEntry:id,entry_date,memo')
            ->get()
            ->map(fn ($l) => [
                'date' => $l->journalEntry->entry_date->toDateString(),
                'details' => $l->description ?: $l->journalEntry->memo,
                'amount' => (float) $l->debit_amount,
            ])->values()->all();

        $vouchers = PettyCashVoucher::with('transactionType')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get();

        $columns = $vouchers->pluck('transactionType.name')->unique()->values()->all();

        $paidRows = $vouchers->map(fn ($v) => [
            'id' => $v->id,
            'date' => $v->date->toDateString(),
            'voucher_no' => $v->voucher_no,
            'details' => $v->details,
            'amount' => (float) $v->amount,
            'column' => $v->transactionType->name,
            'receipt_path' => $v->receipt_path,
        ])->all();

        $columnTotals = collect($paidRows)->groupBy('column')->map(fn ($g) => round($g->sum('amount'), 2))->all();
        $receivedTotal = round(collect($received)->sum('amount'), 2);
        $paidTotal = round(collect($paidRows)->sum('amount'), 2);

        return [
            'month' => $month->format('F Y'),
            'opening_balance' => $opening,
            'received' => $received,
            'received_total' => $receivedTotal,
            'paid' => $paidRows,
            'columns' => $columns,
            'column_totals' => $columnTotals,
            'paid_total' => $paidTotal,
            'closing_balance' => round($opening + $receivedTotal - $paidTotal, 2),
            'ledger_balance' => $this->balanceAsOf($end->toDateString()),
            'replenished' => $this->isMonthReplenished($month),
            'float_amount' => $this->floatAmount(),
        ];
    }

    /**
     * Month-end close: create the replenishment Payment to the custodian
     * for float − closing balance. It rides in the bank payment file with
     * the salaries; approval books debit 1150 / credit 1100.
     */
    public function replenish(Carbon $month): Payment
    {
        if ($this->isMonthReplenished($month)) {
            throw new InvalidArgumentException($month->format('F Y').' has already been replenished.');
        }

        $closing = $this->balanceAsOf($month->copy()->endOfMonth()->toDateString());
        $amount = round($this->floatAmount() - $closing, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Float is already at or above '.number_format($this->floatAmount(), 2).' — nothing to replenish.');
        }

        $custodian = Beneficiary::where('is_petty_cash_custodian', true)->where('is_active', true)->first();

        if (! $custodian) {
            throw new RuntimeException('No petty cash custodian beneficiary configured.');
        }

        $type = TransactionType::byCode('petty-cash-replenishment');

        if (! $type) {
            throw new RuntimeException('Transaction type petty-cash-replenishment not found. Run TransactionTypeSeeder.');
        }

        return Payment::create([
            'payable_type' => Beneficiary::class,
            'payable_id' => $custodian->id,
            'transaction_type_id' => $type->id,
            'company_bank_account_id' => ($type->defaultCompanyBankAccount()
                ?? TransactionType::byCode('salary')?->defaultCompanyBankAccount())?->id,
            'amount' => $amount,
            'details' => $this->replenishmentDetails($month),
            'value_date' => $month->copy()->endOfMonth()->toDateString(),
            'status' => Payment::STATUS_DRAFT,
        ]);
    }

    public function isMonthReplenished(Carbon $month): bool
    {
        return Payment::where('details', $this->replenishmentDetails($month))->exists();
    }

    protected function replenishmentDetails(Carbon $month): string
    {
        return 'Petty cash replenishment '.$month->format('F Y');
    }
}
