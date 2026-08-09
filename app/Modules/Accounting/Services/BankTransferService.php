<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Moving money between the company's own accounts.
 *
 * It was already possible — the register's Add Transaction books any two-line entry
 * — and that is the problem. The same 50,000 moved from the bank to petty cash could
 * be entered as a credit on the bank or a debit on petty cash, by two people, on two
 * screens, with two different descriptions. The money is right either way and the
 * books read as two unrelated events.
 *
 * Here it is one operation with one direction: out of the source, into the
 * destination, described the same way every time, and marked as a transfer so it can
 * be told apart from a payment later.
 */
class BankTransferService
{
    /** Entry type, so a transfer is identifiable rather than just another `general`. */
    public const ENTRY_TYPE = 'transfer';

    public function __construct(
        private JournalEntryService $journalEntries,
        private RegisterEntryService $register,
    ) {}

    /**
     * Between the company's own cash and bank accounts, and only those.
     *
     * Register accounts, the same definition the Account Register uses: an active
     * 11xx asset that takes manual entries. A "transfer" to an expense account is
     * not a transfer, it is a payment, and it has its own screen.
     *
     * @return Collection<int, Account>
     */
    public function accounts(): Collection
    {
        return $this->register->registerAccounts();
    }

    public function transfer(
        Account $from,
        Account $to,
        float $amount,
        string $date,
        ?string $reference = null,
        ?string $note = null,
    ): JournalEntry {
        $amount = round($amount, 2);

        if ($from->getKey() === $to->getKey()) {
            throw new InvalidArgumentException('A transfer needs two different accounts.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('A transfer must be a positive amount.');
        }

        $permitted = $this->accounts()->pluck('id');

        foreach ([$from, $to] as $account) {
            if (! $permitted->contains($account->getKey())) {
                throw new InvalidArgumentException(
                    "{$account->code} {$account->name} is not one of the company's cash or bank accounts. "
                    .'Money leaving the company is a payment, not a transfer.'
                );
            }
        }

        // Said the same way every time, so two transfers of the same money read as
        // the same kind of event a year later.
        $description = $note ?: "Transfer {$from->code} → {$to->code}";

        $entry = $this->journalEntries->create([
            'entry_date' => $date,
            'entry_type' => self::ENTRY_TYPE,
            'memo' => $description.' — '.number_format($amount, 2),
            'reference' => $reference,
        ], [
            ['account_id' => $to->getKey(), 'debit_amount' => $amount, 'description' => $description],
            ['account_id' => $from->getKey(), 'credit_amount' => $amount, 'description' => $description],
        ]);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $this->journalEntries->post($entry);
    }
}
