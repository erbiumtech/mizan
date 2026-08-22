<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Support\Contracts\CsvImporter;
use Illuminate\Support\Collection;

/**
 * A trial balance from whatever the company used before.
 *
 * Lived in `Core\Services\CsvImportService`, which is how Core came to name `Account`, `JournalEntry` and
 * `JournalEntryService` — three of the five classes §9 of docs/module-packaging-plan.md wanted out of it.
 * The accounting judgement here is the reason this is the one import that could not stay: what balances,
 * what 3300 is for, and that the whole thing is one entry are all Accounting's to know.
 */
class OpeningBalanceCsvImporter implements CsvImporter
{
    /** Whatever the rows do not balance to lands here — Opening Balance Equity. */
    private const EQUITY_CODE = '3300';

    public function __construct(private readonly JournalEntryService $entries) {}

    public function key(): string
    {
        return 'opening_balances';
    }

    public function label(): string
    {
        return 'Opening balances';
    }

    public function columns(): array
    {
        return ['account_code', 'debit', 'credit'];
    }

    public function example(): array
    {
        return ['1100', '250000.00', ''];
    }

    public function dateField(): ?array
    {
        return [
            'label' => 'Balances as at',
            'help' => 'The date the opening entry is posted on — usually the day before your first month here.',
        ];
    }

    public function problemWith(array $row): ?string
    {
        if ($row['account_code'] === '') {
            return 'no account code';
        }

        if (! Account::where('code', $row['account_code'])->exists()) {
            return "no account with code {$row['account_code']}";
        }

        $debit = $this->amount($row['debit']);
        $credit = $this->amount($row['credit']);

        if ($debit < 0 || $credit < 0) {
            return 'a negative amount — put it in the other column instead';
        }

        if (($debit > 0) === ($credit > 0)) {
            return 'an amount in both debit and credit, or in neither';
        }

        return null;
    }

    /**
     * Opening balances as one journal entry, balanced by Opening Balance Equity.
     *
     * One entry, not one per row, because a trial balance is a single fact about a single date. Whatever the
     * rows do not balance to lands in 3300, which is what that account is for and what the trial balance and
     * balance sheet both already report on: a half-entered opening position shows up there rather than as an
     * imbalance nobody can see.
     */
    public function write(Collection $rows, ?string $date = null): int
    {
        if ($rows->isEmpty()) {
            return 0;
        }

        $date ??= now()->toDateString();
        $lines = [];
        $net = 0.0;

        foreach ($rows as $row) {
            $account = Account::where('code', $row['account_code'])->firstOrFail();
            $debit = round($this->amount($row['debit']), 2);
            $credit = round($this->amount($row['credit']), 2);

            $lines[] = $debit > 0
                ? ['account_id' => $account->id, 'debit_amount' => $debit, 'description' => 'Opening balance']
                : ['account_id' => $account->id, 'credit_amount' => $credit, 'description' => 'Opening balance'];

            $net = round($net + $debit - $credit, 2);
        }

        if (abs($net) >= 0.005) {
            $equity = Account::where('code', self::EQUITY_CODE)->firstOrFail();

            $lines[] = $net > 0
                ? ['account_id' => $equity->id, 'credit_amount' => $net, 'description' => 'Opening Balance Equity']
                : ['account_id' => $equity->id, 'debit_amount' => -$net, 'description' => 'Opening Balance Equity'];
        }

        $entry = $this->entries->create([
            'entry_date' => $date,
            'entry_type' => 'general',
            'memo' => 'Opening balances imported from CSV',
        ], $lines);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $this->entries->post($entry);

        return $rows->count();
    }

    /**
     * Spreadsheets export thousands separators, and refusing the row over a comma is a poor trade for the
     * person retyping the file.
     */
    private function amount(string $value): float
    {
        return (float) str_replace(',', '', $value ?: '0');
    }
}
