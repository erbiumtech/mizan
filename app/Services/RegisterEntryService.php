<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\TransactionType;
use InvalidArgumentException;

/**
 * GnuCash-style account register: a ledger view with a Transfer
 * (counter-account) column, and quick 2-line entry booking.
 */
class RegisterEntryService
{
    public function __construct(private JournalEntryService $journalEntryService)
    {
    }

    /**
     * Register accounts shown as tabs: postable cash/bank asset accounts.
     */
    public function registerAccounts()
    {
        return Account::where('type', 'asset')
            ->where('allow_manual_entry', true)
            ->where('is_active', true)
            ->where('code', 'like', '11%')
            ->orderBy('code')
            ->get();
    }

    /**
     * The register rows for one account: date, num, description, transfer
     * path, reconciled flag, debit, credit, running balance.
     */
    public function registerRows(Account $account, ?string $from = null, ?string $to = null): array
    {
        $opening = $from ? $this->balanceBefore($account, $from) : 0.0;

        $lines = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                $q->where('is_posted', true);
                if ($from) {
                    $q->whereDate('entry_date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('entry_date', '<=', $to);
                }
            })
            ->with(['journalEntry.lines.account'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id')
            ->select('journal_entry_lines.*')
            ->get();

        $running = $opening;
        $rows = [];

        foreach ($lines as $line) {
            $debit = (float) $line->debit_amount;
            $credit = (float) $line->credit_amount;
            $running += $account->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;

            $rows[] = [
                'date' => $line->journalEntry->entry_date->toDateString(),
                'num' => $line->journalEntry->entry_number,
                'entry_id' => $line->journalEntry->id,
                'description' => $line->description ?: $line->journalEntry->memo,
                'transfer' => $this->transferPath($line),
                'reconciled' => $line->reconciled_at ? 'y' : 'n',
                'debit' => $debit,
                'credit' => $credit,
                'balance' => round($running, 2),
            ];
        }

        return [
            'opening_balance' => round($opening, 2),
            'rows' => $rows,
            'closing_balance' => round($running, 2),
        ];
    }

    /**
     * Book one register row as a balanced 2-line entry, auto-approved and
     * posted (system treatment) so the running balance updates immediately.
     *
     * Money out (credit column) → credit bank, debit transfer.
     * Money in (debit column) → debit bank, credit transfer.
     */
    public function bookRow(Account $bankAccount, Account $transferAccount, array $data): JournalEntry
    {
        if ($bankAccount->id === $transferAccount->id) {
            throw new InvalidArgumentException('Transfer account must differ from the register account.');
        }

        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        $direction = $data['direction']; // 'in' (debit column) or 'out' (credit column)

        $lines = $direction === 'in'
            ? [
                ['account_id' => $bankAccount->id, 'debit_amount' => $amount, 'description' => $data['description']],
                ['account_id' => $transferAccount->id, 'credit_amount' => $amount, 'description' => $data['description']],
            ]
            : [
                ['account_id' => $transferAccount->id, 'debit_amount' => $amount, 'description' => $data['description']],
                ['account_id' => $bankAccount->id, 'credit_amount' => $amount, 'description' => $data['description']],
            ];

        $transactionType = TransactionType::where('account_id', $transferAccount->id)->first();

        $entry = $this->journalEntryService->create([
            'entry_date' => $data['date'],
            'entry_type' => 'general',
            'memo' => $data['description'],
            'reference' => $data['num'] ?? null,
            'transaction_type_id' => $transactionType?->id,
        ], $lines);

        $entry->update([
            'status' => JournalEntry::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $this->journalEntryService->post($entry);

        return $entry;
    }

    /**
     * Accounts offered in the Transfer select: postable, excluding the
     * register account, grouped by type with a GnuCash-style path label.
     */
    public function transferOptions(Account $exclude)
    {
        return Account::where('allow_manual_entry', true)
            ->where('is_active', true)
            ->whereKeyNot($exclude->id)
            ->orderBy('type')
            ->orderBy('code')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'label' => ucfirst($a->type).':'.$a->code.' '.$a->name,
                'type' => $a->type,
            ]);
    }

    /**
     * GnuCash-style Transfer column: the other account(s) of the entry.
     */
    protected function transferPath(JournalEntryLine $line): string
    {
        $others = $line->journalEntry->lines
            ->where('account_id', '!=', $line->account_id)
            ->map(fn ($l) => $l->account ? ucfirst($l->account->type).':'.$l->account->name : '?')
            ->unique()
            ->values();

        return $others->count() > 1 ? '-- Split --' : ($others->first() ?? '');
    }

    protected function balanceBefore(Account $account, string $date): float
    {
        $query = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true)->whereDate('entry_date', '<', $date));

        $debits = (float) (clone $query)->sum('debit_amount');
        $credits = (float) $query->sum('credit_amount');

        return $account->normal_balance === 'debit' ? $debits - $credits : $credits - $debits;
    }
}
