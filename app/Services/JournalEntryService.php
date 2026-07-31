<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class JournalEntryService
{
    /**
     * Create a draft journal entry with its lines.
     *
     * $lines: [['account_id' => 1, 'debit_amount' => 100], ['account_id' => 2, 'credit_amount' => 100], ...]
     */
    public function create(array $header, array $lines): JournalEntry
    {
        $this->validateLines($lines);

        return DB::transaction(function () use ($header, $lines) {
            $entry = JournalEntry::create($header + ['status' => JournalEntry::STATUS_DRAFT]);
            $entry->lines()->createMany($lines);

            activity('JournalEntry')
                ->performedOn($entry)
                ->event('created_with_lines')
                ->withProperties(['entry_number' => $entry->entry_number, 'lines' => count($lines)])
                ->log("Journal entry {$entry->entry_number} created with ".count($lines).' lines');

            return $entry;
        });
    }

    public function submitForApproval(JournalEntry $entry): JournalEntry
    {
        if (! $entry->isEditable()) {
            throw new InvalidArgumentException("Only draft or rejected entries can be submitted (entry is {$entry->status}).");
        }

        if (! $entry->isBalanced()) {
            throw new InvalidArgumentException('Entry must be balanced before submission.');
        }

        $entry->update([
            'status' => JournalEntry::STATUS_PENDING,
            'rejection_reason' => null,
        ]);

        return $entry;
    }

    public function approve(JournalEntry $entry, User $approver): JournalEntry
    {
        if ($entry->status !== JournalEntry::STATUS_PENDING) {
            throw new InvalidArgumentException("Only pending entries can be approved (entry is {$entry->status}).");
        }

        if ($entry->created_by !== null && $entry->created_by === $approver->id) {
            throw new InvalidArgumentException('An entry cannot be approved by its creator (segregation of duties).');
        }

        $entry->update([
            'status' => JournalEntry::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        activity('JournalEntry')
            ->performedOn($entry)
            ->causedBy($approver)
            ->event('approved')
            ->withProperties(['entry_number' => $entry->entry_number])
            ->log("Journal entry {$entry->entry_number} approved");

        return $entry;
    }

    public function reject(JournalEntry $entry, User $approver, string $reason): JournalEntry
    {
        if ($entry->status !== JournalEntry::STATUS_PENDING) {
            throw new InvalidArgumentException("Only pending entries can be rejected (entry is {$entry->status}).");
        }

        $entry->update([
            'status' => JournalEntry::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        activity('JournalEntry')
            ->performedOn($entry)
            ->causedBy($approver)
            ->event('rejected')
            ->withProperties(['entry_number' => $entry->entry_number, 'reason' => $reason])
            ->log("Journal entry {$entry->entry_number} rejected: {$reason}");

        return $entry;
    }

    /**
     * Post an approved, balanced entry: apply signed deltas to account balances.
     */
    public function post(JournalEntry $entry): JournalEntry
    {
        if ($entry->is_posted) {
            throw new InvalidArgumentException('Journal entry is already posted.');
        }

        if (! $entry->isApproved()) {
            throw new InvalidArgumentException('Journal entry must be approved before posting.');
        }

        if (! $entry->isBalanced()) {
            throw new InvalidArgumentException('Journal entry must be balanced before posting.');
        }

        // A closed period is frozen. Checked here rather than at creation so a
        // draft can still be written and then re-dated, but nothing reaches the
        // ledger of a year someone has signed off.
        $period = FiscalYear::containing($entry->entry_date->toDateString());

        if ($period?->isClosed()) {
            throw new InvalidArgumentException(
                "Fiscal year {$period->name} is closed; reopen it to post entries dated "
                .$entry->entry_date->toDateString().'.'
            );
        }

        DB::transaction(function () use ($entry) {
            foreach ($entry->lines()->with('account')->get() as $line) {
                $account = Account::lockForUpdate()->find($line->account_id);

                $delta = $account->normal_balance === 'debit'
                    ? $line->debit_amount - $line->credit_amount
                    : $line->credit_amount - $line->debit_amount;

                $account->balance = (float) $account->balance + (float) $delta;
                $account->save();
            }

            $entry->update([
                'status' => JournalEntry::STATUS_POSTED,
                'is_posted' => true,
                'posted_at' => now(),
            ]);
        });

        activity('JournalEntry')
            ->performedOn($entry)
            ->event('posted')
            ->withProperties([
                'entry_number' => $entry->entry_number,
                'total_debits' => $entry->total_debits,
                'total_credits' => $entry->total_credits,
            ])
            ->log("Journal entry {$entry->entry_number} posted");

        return $entry;
    }

    /**
     * Reverse a posted entry by creating and posting a mirrored reversing entry.
     * The original stays posted; the reversal restores account balances.
     */
    public function reverse(JournalEntry $entry, ?User $approver = null): JournalEntry
    {
        if (! $entry->is_posted) {
            throw new InvalidArgumentException('Only posted entries can be reversed.');
        }

        return DB::transaction(function () use ($entry, $approver) {
            $reversal = JournalEntry::create([
                'entry_date' => now()->toDateString(),
                'reference' => $entry->entry_number,
                'memo' => "Reversal of {$entry->entry_number}".($entry->memo ? " — {$entry->memo}" : ''),
                'entry_type' => 'reversing',
                'status' => JournalEntry::STATUS_APPROVED,
                'approved_by' => $approver?->id ?? $entry->approved_by,
                'approved_at' => now(),
                'fiscal_year_id' => $entry->fiscal_year_id,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
            ]);

            foreach ($entry->lines as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'debit_amount' => $line->credit_amount,
                    'credit_amount' => $line->debit_amount,
                    'description' => $line->description,
                ]);
            }

            $this->post($reversal);

            activity('JournalEntry')
                ->performedOn($entry)
                ->event('reversed')
                ->withProperties([
                    'entry_number' => $entry->entry_number,
                    'reversal_number' => $reversal->entry_number,
                ])
                ->log("Journal entry {$entry->entry_number} reversed by {$reversal->entry_number}");

            return $reversal;
        });
    }

    protected function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least two lines.');
        }

        $debits = 0.0;
        $credits = 0.0;

        foreach ($lines as $i => $line) {
            $debit = (float) ($line['debit_amount'] ?? 0);
            $credit = (float) ($line['credit_amount'] ?? 0);

            if (($debit > 0) === ($credit > 0)) {
                throw new InvalidArgumentException("Line {$i}: each line must have either a debit or a credit amount (not both, not neither).");
            }

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException("Line {$i}: amounts cannot be negative.");
            }

            $account = Account::find($line['account_id'] ?? null);

            if (! $account) {
                throw new InvalidArgumentException("Line {$i}: account not found.");
            }

            if (! $account->canAcceptEntries()) {
                throw new InvalidArgumentException("Line {$i}: account {$account->code} ({$account->name}) cannot accept entries.");
            }

            $debits += $debit;
            $credits += $credit;
        }

        if (bccomp(number_format($debits, 2, '.', ''), number_format($credits, 2, '.', ''), 2) !== 0) {
            throw new InvalidArgumentException(
                sprintf('Entry is not balanced: debits %.2f != credits %.2f.', $debits, $credits)
            );
        }
    }
}
