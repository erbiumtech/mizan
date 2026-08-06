<?php

namespace App\Modules\PersonalFinance\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Models\PersonalEntry;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * Writes entries into a person's own books.
 *
 * The validation mirrors JournalEntryService::validateLines(), because those
 * rules are what make a ledger a ledger: at least two lines, each one a debit or
 * a credit but never both, nothing negative, and the two sides equal.
 *
 * On top of that there are three verbs — income, expense, transfer — because
 * nobody is going to hand-write balanced pairs to record buying lunch. Each
 * builds the two lines itself, which is the same trick RegisterEntryService
 * uses to keep the Account Register usable.
 */
class PersonalEntryService
{
    /**
     * @param  array<int, array{account_id: int, debit?: float|int|string, credit?: float|int|string}>  $lines
     */
    public function create(array $data, array $lines): PersonalEntry
    {
        $this->validateLines($lines);

        return TenantTransaction::run(function () use ($data, $lines) {
            $date = $data['date'] ?? now()->toDateString();

            $entry = PersonalEntry::create([
                'date' => $date,
                'description' => $data['description'] ?? '',
                'fiscal_year_id' => $data['fiscal_year_id'] ?? FiscalYear::containing((string) $date)?->id,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'personal_account_id' => $line['account_id'],
                    'debit' => round((float) ($line['debit'] ?? 0), 2),
                    'credit' => round((float) ($line['credit'] ?? 0), 2),
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Money arriving: the account it lands in gains, the income category is
     * credited.
     */
    public function recordIncome(PersonalAccount $into, PersonalAccount $category, float $amount, array $data = []): PersonalEntry
    {
        $this->assertPositive($amount);

        return $this->create($data, [
            ['account_id' => $into->id, 'debit' => $amount],
            ['account_id' => $category->id, 'credit' => $amount],
        ]);
    }

    /**
     * Money leaving: the expense category is charged, the account it came out of
     * drops.
     */
    public function recordExpense(PersonalAccount $category, PersonalAccount $from, float $amount, array $data = []): PersonalEntry
    {
        $this->assertPositive($amount);

        return $this->create($data, [
            ['account_id' => $category->id, 'debit' => $amount],
            ['account_id' => $from->id, 'credit' => $amount],
        ]);
    }

    /** Money moving between two of the person's own accounts. Net worth unchanged. */
    public function transfer(PersonalAccount $from, PersonalAccount $to, float $amount, array $data = []): PersonalEntry
    {
        $this->assertPositive($amount);

        if ($from->is($to)) {
            throw new InvalidArgumentException('A transfer needs two different accounts.');
        }

        return $this->create($data, [
            ['account_id' => $to->id, 'debit' => $amount],
            ['account_id' => $from->id, 'credit' => $amount],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('An entry needs at least two lines.');
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $index => $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            $position = $index + 1;

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException("Line {$position}: an amount cannot be negative.");
            }

            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException("Line {$position}: a line is a debit or a credit, not both.");
            }

            if ($debit === 0.0 && $credit === 0.0) {
                throw new InvalidArgumentException("Line {$position}: needs a debit or a credit.");
            }

            if (empty($line['account_id'])) {
                throw new InvalidArgumentException("Line {$position}: needs an account.");
            }

            // Also proves the account is the signed-in person's own: the global
            // owner scope is on this query, so somebody else's id finds nothing.
            $account = PersonalAccount::find($line['account_id']);

            if ($account === null) {
                throw new InvalidArgumentException("Line {$position}: that account does not exist.");
            }

            if (! $account->is_active) {
                throw new InvalidArgumentException("Line {$position}: {$account->name} is closed.");
            }

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        // Compared as strings at 2dp: 0.1 + 0.2 !== 0.3 in binary floating point,
        // and a ledger that rejects a correct entry is worse than one that is slow.
        if (bccomp(number_format($totalDebit, 2, '.', ''), number_format($totalCredit, 2, '.', ''), 2) !== 0) {
            throw new InvalidArgumentException(sprintf(
                'The entry does not balance: debits %s, credits %s.',
                number_format($totalDebit, 2),
                number_format($totalCredit, 2),
            ));
        }
    }

    private function assertPositive(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('The amount has to be more than zero.');
        }
    }
}
