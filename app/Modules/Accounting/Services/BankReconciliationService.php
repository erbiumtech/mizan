<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BankReconciliationService
{
    /** Auto-match date window, in days, either side of the statement line date. */
    private const DATE_WINDOW_DAYS = 3;

    public function __construct(private GeneralLedgerService $generalLedger)
    {
    }

    /**
     * Import CSV-style rows into statement lines.
     *
     * Each row: ['transaction_date' => ..., 'description' => ..., 'reference' => ..., 'amount' => (signed)].
     * Returns the created lines.
     */
    public function import(array $rows, BankStatement $statement): array
    {
        if ($statement->isCompleted()) {
            throw new InvalidArgumentException('Cannot import into a completed statement.');
        }

        $created = [];

        DB::transaction(function () use ($rows, $statement, &$created) {
            foreach ($rows as $i => $row) {
                if (! array_key_exists('amount', $row) || $row['amount'] === null || $row['amount'] === '') {
                    throw new InvalidArgumentException("Row {$i}: amount is required.");
                }

                if (empty($row['transaction_date'])) {
                    throw new InvalidArgumentException("Row {$i}: transaction_date is required.");
                }

                $created[] = $statement->lines()->create([
                    'transaction_date' => Carbon::parse($row['transaction_date'])->toDateString(),
                    'description' => $row['description'] ?? null,
                    'reference' => $row['reference'] ?? null,
                    'amount' => round((float) $row['amount'], 2),
                    'match_status' => BankStatementLine::STATUS_UNMATCHED,
                ]);
            }

            if ($statement->status === BankStatement::STATUS_DRAFT) {
                $statement->update(['status' => BankStatement::STATUS_IN_PROGRESS]);
            }
        });

        activity('BankStatement')
            ->performedOn($statement)
            ->event('imported')
            ->withProperties(['rows' => count($created)])
            ->log("Imported {$statement->id}: " . count($created) . ' statement line(s)');

        return $created;
    }

    /**
     * Auto-match unmatched statement lines against unreconciled posted ledger
     * lines on the same account. Two passes, one-to-one only, ties skipped:
     *   1. exact amount + transaction date within ±3 days,
     *   2. exact amount + reference containing the entry number.
     * Returns the number of lines matched.
     */
    public function autoMatch(BankStatement $statement): int
    {
        if ($statement->isCompleted()) {
            throw new InvalidArgumentException('Cannot match a completed statement.');
        }

        $matched = 0;

        DB::transaction(function () use ($statement, &$matched) {
            foreach ($statement->lines()->where('match_status', BankStatementLine::STATUS_UNMATCHED)->get() as $line) {
                $candidates = $this->candidateLedgerLines($statement)
                    ->filter(fn (JournalEntryLine $l) => $this->amountsMatch($l, $line));

                $ledgerLine = $this->pickCandidate($candidates, $line);

                if ($ledgerLine) {
                    $this->applyMatch($line, $ledgerLine, BankStatementLine::STATUS_AUTO_MATCHED);
                    $matched++;
                }
            }
        });

        if ($statement->status === BankStatement::STATUS_DRAFT) {
            $statement->update(['status' => BankStatement::STATUS_IN_PROGRESS]);
        }

        activity('BankStatement')
            ->performedOn($statement)
            ->event('auto_matched')
            ->withProperties(['matched' => $matched])
            ->log("Auto-matched {$matched} line(s) on statement {$statement->id}");

        return $matched;
    }

    /**
     * Manually match a statement line to a specific ledger line.
     */
    public function match(BankStatementLine $line, JournalEntryLine $ledgerLine): BankStatementLine
    {
        $this->guardStatementOpen($line);

        if ($line->isMatched()) {
            throw new InvalidArgumentException('Statement line is already matched; unmatch it first.');
        }

        if ($ledgerLine->account_id !== $line->bankStatement->account_id) {
            throw new InvalidArgumentException('Ledger line belongs to a different account.');
        }

        if ($ledgerLine->isReconciled()) {
            throw new InvalidArgumentException('Ledger line is already reconciled.');
        }

        $this->applyMatch($line, $ledgerLine, BankStatementLine::STATUS_MANUALLY_MATCHED);

        return $line->refresh();
    }

    /**
     * Undo a match, freeing both the statement line and the ledger line.
     */
    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        $this->guardStatementOpen($line);

        if (! $line->isMatched()) {
            throw new InvalidArgumentException('Statement line is not matched.');
        }

        DB::transaction(function () use ($line) {
            if ($line->matchedLine) {
                $line->matchedLine->update(['reconciled_at' => null]);
            }

            $line->update([
                'matched_line_id' => null,
                'match_status' => BankStatementLine::STATUS_UNMATCHED,
            ]);
        });

        return $line->refresh();
    }

    /**
     * Exclude a line from reconciliation (e.g. a bank fee with no ledger entry).
     */
    public function exclude(BankStatementLine $line): BankStatementLine
    {
        $this->guardStatementOpen($line);

        DB::transaction(function () use ($line) {
            if ($line->matchedLine) {
                $line->matchedLine->update(['reconciled_at' => null]);
            }

            $line->update([
                'matched_line_id' => null,
                'match_status' => BankStatementLine::STATUS_EXCLUDED,
            ]);
        });

        return $line->refresh();
    }

    /**
     * Complete the reconciliation. Allowed only when every line is matched or
     * excluded and the statement closing balance equals the ledger balance as
     * of the statement date. Locks the statement.
     */
    public function complete(BankStatement $statement, User $user): BankStatement
    {
        if ($statement->isCompleted()) {
            throw new InvalidArgumentException('Statement is already completed.');
        }

        if (! $statement->isFullyMatched()) {
            throw new InvalidArgumentException('Every line must be matched or excluded before completing.');
        }

        $ledgerBalance = $this->ledgerBalance($statement);

        if (bccomp(
            number_format((float) $statement->closing_balance, 2, '.', ''),
            number_format($ledgerBalance, 2, '.', ''),
            2
        ) !== 0) {
            throw new InvalidArgumentException(sprintf(
                'Closing balance %.2f does not match ledger balance %.2f as of %s.',
                (float) $statement->closing_balance,
                $ledgerBalance,
                $statement->statement_date->toDateString()
            ));
        }

        $statement->update([
            'status' => BankStatement::STATUS_COMPLETED,
            'completed_by' => $user->id,
            'completed_at' => now(),
        ]);

        activity('BankStatement')
            ->performedOn($statement)
            ->causedBy($user)
            ->event('completed')
            ->withProperties(['closing_balance' => (float) $statement->closing_balance])
            ->log("Bank statement {$statement->id} reconciliation completed");

        return $statement;
    }

    /**
     * The ledger balance of the statement's account as of the statement date.
     */
    public function ledgerBalance(BankStatement $statement): float
    {
        return $this->generalLedger->accountLedger(
            $statement->account,
            null,
            $statement->statement_date->toDateString()
        )['closing_balance'];
    }

    /**
     * Unreconciled posted ledger lines on the statement's account, excluding any
     * already claimed by another statement line.
     *
     * @return \Illuminate\Support\Collection<int, JournalEntryLine>
     */
    protected function candidateLedgerLines(BankStatement $statement): \Illuminate\Support\Collection
    {
        return JournalEntryLine::query()
            ->where('account_id', $statement->account_id)
            ->whereNull('reconciled_at')
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true))
            ->whereDoesntHave('bankStatementLine')
            ->with('journalEntry:id,entry_number,entry_date')
            ->get();
    }

    protected function pickCandidate(\Illuminate\Support\Collection $candidates, BankStatementLine $line): ?JournalEntryLine
    {
        // Pass 1: exact amount + date within the window.
        $byDate = $candidates->filter(function (JournalEntryLine $l) use ($line) {
            $entryDate = $l->journalEntry->entry_date;

            return abs($entryDate->diffInDays($line->transaction_date)) <= self::DATE_WINDOW_DAYS;
        });

        if ($byDate->count() === 1) {
            return $byDate->first();
        }

        if ($byDate->count() > 1) {
            // Ambiguous tie on amount+date: leave for manual review.
            return null;
        }

        // Pass 2: exact amount + reference contains the entry number.
        if (! empty($line->reference)) {
            $byReference = $candidates->filter(
                fn (JournalEntryLine $l) => str_contains($line->reference, $l->journalEntry->entry_number)
            );

            if ($byReference->count() === 1) {
                return $byReference->first();
            }
        }

        return null;
    }

    protected function amountsMatch(JournalEntryLine $ledgerLine, BankStatementLine $statementLine): bool
    {
        return bccomp(
            number_format($ledgerLine->signed_amount, 2, '.', ''),
            number_format((float) $statementLine->amount, 2, '.', ''),
            2
        ) === 0;
    }

    protected function applyMatch(BankStatementLine $line, JournalEntryLine $ledgerLine, string $status): void
    {
        DB::transaction(function () use ($line, $ledgerLine, $status) {
            $ledgerLine->update(['reconciled_at' => now()]);

            $line->update([
                'matched_line_id' => $ledgerLine->id,
                'match_status' => $status,
            ]);
        });
    }

    protected function guardStatementOpen(BankStatementLine $line): void
    {
        if ($line->bankStatement->isCompleted()) {
            throw new InvalidArgumentException('Statement is completed and locked.');
        }
    }
}
