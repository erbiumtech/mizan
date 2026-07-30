<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PettyCashVoucher;
use App\Modules\Inventory\Models\StockMovement;
use App\Models\TransactionType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * GnuCash-style account register: a ledger view with a Transfer
 * (counter-account) column, and quick 2-line entry booking.
 */
class RegisterEntryService
{
    public function __construct(private JournalEntryService $journalEntryService) {}

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
                // Rows booked elsewhere (payments, vouchers, payroll…) are shown
                // but cannot be changed from here; the reason is the tooltip.
                'immutable_reason' => $this->immutableReason($line->journalEntry),
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
     * Why this entry cannot be changed from the register, or null if it can.
     *
     * The register only owns the rows it booked itself. Everything else that
     * touches a cash account — a payment, an invoice, a petty cash voucher, a
     * stock movement, depreciation, payroll — appears here too, and its journal
     * entry is the accounting half of a document that would silently
     * desynchronise if the entry were edited behind its back. Those must be
     * corrected where they came from.
     */
    public function immutableReason(JournalEntry $entry): ?string
    {
        if ($entry->entry_type === 'reversing') {
            return 'This is a reversing entry. Reversals are a permanent record and are never edited.';
        }

        if ($entry->source_type) {
            return 'This entry was booked by '.class_basename($entry->source_type)
                .' #'.$entry->source_id.'. Correct it there instead.';
        }

        $owners = [
            'a payment' => Payment::class,
            'an invoice' => Invoice::class,
            'a petty cash voucher' => PettyCashVoucher::class,
            'a stock movement' => StockMovement::class,
            'a fixed asset' => FixedAsset::class,
        ];

        foreach ($owners as $label => $model) {
            $owner = $model::where('journal_entry_id', $entry->id)->first();

            if ($owner) {
                return 'This entry belongs to '.$label.' (#'.$owner->getKey().'). Correct it there instead.';
            }
        }

        $lines = $entry->lines()->get();

        if ($lines->count() !== 2) {
            return 'This is a split entry ('.$lines->count().' lines). Edit it in the journal, or reverse it.';
        }

        if ($lines->contains(fn (JournalEntryLine $line) => $line->reconciled_at !== null)) {
            return 'This entry is reconciled against a bank statement. Reverse it instead of changing it.';
        }

        return null;
    }

    public function isEditableFromRegister(JournalEntry $entry): bool
    {
        return $this->immutableReason($entry) === null;
    }

    /**
     * Restate a register-booked row in place: date, description, num, transfer
     * account, direction and amount.
     *
     * Posted entries are immutable elsewhere in this system and corrections go
     * through reversal — see JournalEntryPolicy. The register is the deliberate
     * exception: it is a fast data-entry surface for cash and bank accounts, and
     * a mistyped amount or description should not require a reversal pair that
     * doubles the row count of the very ledger someone is trying to read.
     * The trade-off is bounded by immutableReason() above — anything owned by
     * another document, reconciled, or split cannot come through here — and every
     * restatement is written to the activity log with its before/after values.
     */
    public function updateRow(JournalEntry $entry, Account $registerAccount, array $data): JournalEntry
    {
        if ($reason = $this->immutableReason($entry)) {
            throw new InvalidArgumentException($reason);
        }

        $transferAccount = Account::findOrFail($data['transfer_account_id']);

        if ($transferAccount->id === $registerAccount->id) {
            throw new InvalidArgumentException('Transfer account must differ from the register account.');
        }

        if (! $transferAccount->canAcceptEntries()) {
            throw new InvalidArgumentException("Account {$transferAccount->code} ({$transferAccount->name}) cannot accept entries.");
        }

        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        $direction = $data['direction'];
        $before = $this->snapshot($entry, $registerAccount);

        DB::transaction(function () use ($entry, $registerAccount, $transferAccount, $amount, $direction, $data): void {
            // Undo the old lines' effect on both accounts, then apply the new
            // ones. Doing it in two passes handles a changed transfer account
            // without special-casing it.
            $lines = $entry->lines()->get();

            foreach ($lines as $line) {
                $this->applyBalance($line->account_id, (float) $line->debit_amount, (float) $line->credit_amount, -1);
            }

            $entry->lines()->delete();

            $newLines = $direction === 'in'
                ? [
                    ['account_id' => $registerAccount->id, 'debit_amount' => $amount, 'credit_amount' => 0],
                    ['account_id' => $transferAccount->id, 'debit_amount' => 0, 'credit_amount' => $amount],
                ]
                : [
                    ['account_id' => $transferAccount->id, 'debit_amount' => $amount, 'credit_amount' => 0],
                    ['account_id' => $registerAccount->id, 'debit_amount' => 0, 'credit_amount' => $amount],
                ];

            foreach ($newLines as $line) {
                $entry->lines()->create($line + ['description' => $data['description']]);
                $this->applyBalance($line['account_id'], $line['debit_amount'], $line['credit_amount'], 1);
            }

            $entry->update([
                'entry_date' => $data['date'],
                'memo' => $data['description'],
                'reference' => $data['num'] ?? null,
                'transaction_type_id' => TransactionType::where('account_id', $transferAccount->id)->first()?->id,
            ]);
        });

        $entry->refresh();

        activity('JournalEntry')
            ->performedOn($entry)
            ->event('restated_from_register')
            ->withProperties([
                'entry_number' => $entry->entry_number,
                'register_account' => $registerAccount->code,
                'before' => $before,
                'after' => $this->snapshot($entry, $registerAccount),
            ])
            ->log("Journal entry {$entry->entry_number} restated from the account register");

        return $entry;
    }

    /**
     * Remove a register-booked row: unwind its effect on both account balances
     * and delete it.
     *
     * A hard delete loses the row itself, which is why it is limited to entries
     * the register owns and why the activity log keeps a full copy of what was
     * deleted. For anything that must stay on the face of the ledger, use
     * JournalEntryService::reverse() instead.
     */
    public function deleteRow(JournalEntry $entry, Account $registerAccount): void
    {
        if ($reason = $this->immutableReason($entry)) {
            throw new InvalidArgumentException($reason);
        }

        $snapshot = $this->snapshot($entry, $registerAccount);
        $entryNumber = $entry->entry_number;

        DB::transaction(function () use ($entry): void {
            foreach ($entry->lines()->get() as $line) {
                $this->applyBalance($line->account_id, (float) $line->debit_amount, (float) $line->credit_amount, -1);
            }

            // Lines cascade on delete at the database level.
            $entry->delete();
        });

        activity('JournalEntry')
            ->event('deleted_from_register')
            ->withProperties([
                'entry_number' => $entryNumber,
                'register_account' => $registerAccount->code,
                'deleted' => $snapshot,
            ])
            ->log("Journal entry {$entryNumber} deleted from the account register");
    }

    /**
     * Move an account's stored balance by a line's signed effect.
     * $sign of -1 unwinds it, +1 applies it — same math as
     * JournalEntryService::post().
     */
    protected function applyBalance(int $accountId, float $debit, float $credit, int $sign): void
    {
        $account = Account::lockForUpdate()->find($accountId);

        if (! $account) {
            return;
        }

        $delta = $account->normal_balance === 'debit'
            ? $debit - $credit
            : $credit - $debit;

        $account->balance = (float) $account->balance + ($sign * $delta);
        $account->save();
    }

    /**
     * The register-facing shape of an entry, for the audit log.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(JournalEntry $entry, Account $registerAccount): array
    {
        $lines = $entry->lines()->get();
        $own = $lines->firstWhere('account_id', $registerAccount->id);
        $other = $lines->first(fn (JournalEntryLine $line) => $line->account_id !== $registerAccount->id);

        return [
            'date' => $entry->entry_date?->toDateString(),
            'num' => $entry->reference,
            'description' => $entry->memo,
            'debit' => (float) ($own->debit_amount ?? 0),
            'credit' => (float) ($own->credit_amount ?? 0),
            'transfer_account' => $other ? Account::find($other->account_id)?->code : null,
        ];
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
