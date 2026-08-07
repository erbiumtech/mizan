<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Loan;
use App\Modules\Accounting\Models\LoanInstalment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The amortisation table, and turning one of its rows into a journal entry.
 */
class LoanService
{
    public function __construct(private JournalEntryService $entries) {}

    /**
     * The level instalment: the same amount every month that clears the loan in
     * exactly the term.
     *
     *     payment = P × r / (1 − (1 + r)^−n)
     *
     * At a zero rate that formula divides by zero, and the answer there is
     * simply the principal split evenly — an interest-free arrangement between
     * family, or a staff loan, is a real thing and not an error.
     */
    public function instalmentAmount(float $principal, float $monthlyRate, int $months): float
    {
        if ($months < 1) {
            throw new RuntimeException('A loan needs a term of at least one month.');
        }

        if (abs($monthlyRate) < 1e-12) {
            return round($principal / $months, 2);
        }

        return round(
            $principal * $monthlyRate / (1 - (1 + $monthlyRate) ** -$months),
            2,
        );
    }

    /**
     * Build (or rebuild) the whole schedule.
     *
     * Rebuilding refuses once anything has been recorded. An amortisation table
     * is a promise about specific amounts on specific dates, and half of it has
     * already been posted to the ledger — regenerating would leave entries that
     * no longer match any row and a liability that no longer reaches zero.
     *
     * @return array<int, LoanInstalment>
     */
    public function generateSchedule(Loan $loan): array
    {
        if ($loan->instalments()->whereNotNull('journal_entry_id')->exists()) {
            throw new RuntimeException(
                'Instalments have already been recorded against this loan, so its schedule cannot be rebuilt.'
            );
        }

        $rows = $this->schedule(
            (float) $loan->principal,
            $loan->monthlyRate(),
            $loan->term_months,
            CarbonImmutable::parse($loan->starts_on),
        );

        return DB::connection($loan->getConnectionName())->transaction(function () use ($loan, $rows): array {
            $loan->instalments()->delete();

            foreach ($rows as $row) {
                $loan->instalments()->create($row);
            }

            return $loan->instalments()->get()->all();
        });
    }

    /**
     * The table itself, as plain arrays — no model, no database, so it can be
     * previewed on a form before anything is saved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schedule(float $principal, float $monthlyRate, int $months, CarbonImmutable $firstDue): array
    {
        $payment = $this->instalmentAmount($principal, $monthlyRate, $months);
        $balance = round($principal, 2);
        $rows = [];

        for ($n = 1; $n <= $months; $n++) {
            $interest = round($balance * $monthlyRate, 2);
            $isLast = $n === $months;

            // The last instalment pays off whatever is actually left rather than
            // the level amount. Rounding each month to the paisa leaves the
            // schedule a few rupees either side of zero after a few hundred
            // instalments, and a loan that finishes owing 3.71 is a loan
            // somebody has to write a journal entry to close.
            $principalPart = $isLast ? $balance : round($payment - $interest, 2);

            // A rate high enough that the interest alone exceeds the level
            // payment would amortise negatively — the balance growing every
            // month, forever. That is a real financial instrument and not one
            // this schedule models, so it is refused rather than rendered.
            if (! $isLast && $principalPart <= 0) {
                throw new RuntimeException(
                    'At this rate the interest is more than the instalment, so the balance would never fall. '
                    .'Check the rate and the term.'
                );
            }

            $closing = round($balance - $principalPart, 2);

            $rows[] = [
                'number' => $n,
                'due_on' => $this->dueDate($firstDue, $n)->toDateString(),
                'opening_balance' => $balance,
                'payment' => round($principalPart + $interest, 2),
                'interest' => $interest,
                'principal' => $principalPart,
                'closing_balance' => $closing,
            ];

            $balance = $closing;
        }

        return $rows;
    }

    /**
     * The nth instalment date, counted in months from the first.
     *
     * Clamped to the month's length, so a loan whose first payment is on the
     * 31st does not skip February — the same rule scheduled transactions use.
     */
    private function dueDate(CarbonImmutable $firstDue, int $n): CarbonImmutable
    {
        $day = $firstDue->day;
        $month = $firstDue->startOfMonth()->addMonths($n - 1);

        return $month->setDay(min($day, $month->daysInMonth));
    }

    /**
     * Record an instalment: a three-sided entry, raised as a draft.
     *
     *   Dr  Loan liability      the principal portion — what is no longer owed
     *   Dr  Interest expense    the cost of having borrowed it this month
     *   Cr  Cash or bank        the whole payment, which is what actually left
     *
     * A draft, for the same reason every other automatic entry here is one: it
     * still goes through approval before it reaches the books.
     */
    public function recordInstalment(LoanInstalment $instalment, ?string $date = null): JournalEntry
    {
        if ($instalment->isRecorded()) {
            throw new RuntimeException("Instalment {$instalment->number} has already been recorded.");
        }

        $loan = $instalment->loan;

        $lines = [
            [
                'account_id' => $loan->liability_account_id,
                'debit_amount' => (float) $instalment->principal,
                'description' => "Principal, instalment {$instalment->number}",
            ],
            [
                'account_id' => $loan->payment_account_id,
                'credit_amount' => (float) $instalment->payment,
                'description' => "Instalment {$instalment->number} of {$loan->term_months}",
            ],
        ];

        // An interest-free loan has no interest line at all. A zero line would
        // balance perfectly well and put a row of nothing on every entry.
        if ((float) $instalment->interest > 0) {
            array_splice($lines, 1, 0, [[
                'account_id' => $loan->interest_account_id,
                'debit_amount' => (float) $instalment->interest,
                'description' => "Interest, instalment {$instalment->number}",
            ]]);
        }

        return DB::connection($loan->getConnectionName())->transaction(function () use ($instalment, $loan, $lines, $date): JournalEntry {
            $entry = $this->entries->create(
                [
                    'entry_date' => $date ?: $instalment->due_on->toDateString(),
                    'entry_type' => 'general',
                    'memo' => "{$loan->name} — instalment {$instalment->number} of {$loan->term_months}",
                    'source_type' => Loan::class,
                    'source_id' => $loan->getKey(),
                ],
                $lines,
            );

            $instalment->update(['journal_entry_id' => $entry->getKey()]);

            return $entry;
        });
    }
}
