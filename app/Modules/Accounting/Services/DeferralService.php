<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\ScheduledTransaction;
use App\Support\TenantTransaction;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Revenue billed now and earned later, and the expense paid now and incurred later —
 * `docs/erpnext-gap-plan.md` Phase 5.
 *
 * **The whole item is "express it with what is already here".** ERPNext has a deferred-revenue engine: a
 * flag on the item, service start and end dates on the invoice row, a company-wide choice between monthly
 * and day-prorated recognition, and a scheduled job that books the month's share. This application already
 * has the dated recurring posting — `ScheduledTransaction`, which raises the same entry every month, on a
 * day, between two dates, and stops. What was missing was not machinery: it was the *arithmetic* and the
 * pair of accounts, which is what this class is.
 *
 * So a deferral here is two things, and neither is new:
 *
 *  1. **one entry now**, moving the amount out of income into a liability — the money is owed as service,
 *     not earned;
 *  2. **a schedule**, bringing one month's share back into income until it is used up.
 *
 * **Whole months, never prorated by day.** ERPNext offers both and the choice is company-wide; offering it
 * would mean a setting, a second arithmetic, and a report that has to say which one a given deferral used.
 * A month's share of an annual licence is what an accountant recognises, and a company that genuinely needs
 * day-prorated revenue has a materiality problem this application is not the place to solve.
 *
 * **The remainder is recognised immediately rather than left to rot.** 1,000 over three months is 333.33 a
 * month, which is 999.99 — and a fixed-line schedule cannot vary its last posting. Deferring only the even
 * part leaves nothing behind: the cent stays in income, where it already was. The alternative is a liability
 * account with a permanent one-cent balance in it per deferral, and nobody ever clears those.
 */
class DeferralService
{
    /** Revenue billed and not yet earned. A liability: the company owes the service. */
    public const DEFERRED_REVENUE_CODE = '2500';

    /** Cost paid and not yet incurred — an annual licence, insurance, rent in advance. An asset. */
    public const PREPAID_EXPENSE_CODE = '1350';

    public function __construct(private JournalEntryService $entries) {}

    /**
     * Take an amount out of income now and bring it back a month at a time.
     *
     * @param  int  $months  how many monthly shares to spread it over
     * @param  string  $startsOn  the first month to recognise. Usually the month after the deferral: the
     *                            month being billed for is normally already earned.
     * @return array{entry: JournalEntry, schedule: ScheduledTransaction, monthly: float, deferred: float}
     */
    public function deferRevenue(
        float $amount,
        int $months,
        string $startsOn,
        string $description,
        ?int $incomeAccountId = null,
    ): array {
        return $this->defer(
            amount: $amount,
            months: $months,
            startsOn: $startsOn,
            description: $description,
            // Out of income: income is credit-normal, so taking revenue back out of it is a debit.
            operatingAccountId: $incomeAccountId ?? $this->accountFor('4100', 'income'),
            holdingAccountId: $this->accountFor(self::DEFERRED_REVENUE_CODE, 'deferred revenue'),
            deferralDebitsOperating: true,
        );
    }

    /**
     * The mirror: hold a cost as an asset now and charge it a month at a time.
     *
     * Included because it is the same two lines with the accounts swapped, and leaving it out would have
     * meant a second class doing the mirror later — which is how two implementations of one idea start.
     *
     * @return array{entry: JournalEntry, schedule: ScheduledTransaction, monthly: float, deferred: float}
     */
    public function deferExpense(
        float $amount,
        int $months,
        string $startsOn,
        string $description,
        ?int $expenseAccountId = null,
    ): array {
        return $this->defer(
            amount: $amount,
            months: $months,
            startsOn: $startsOn,
            description: $description,
            operatingAccountId: $expenseAccountId ?? $this->accountFor('5900', 'expense'),
            holdingAccountId: $this->accountFor(self::PREPAID_EXPENSE_CODE, 'prepaid expenses'),
            // Out of expense: expense is debit-normal, so taking a cost back out of it is a credit.
            deferralDebitsOperating: false,
        );
    }

    /**
     * Both directions, once.
     *
     * The only difference between deferring income and deferring cost is which side of the initial entry the
     * operating account sits on — and the schedule is always that entry backwards. Written once because two
     * copies of this would be two chances for a schedule to point the wrong way, which is a mistake that
     * looks like a working feature until the year end.
     *
     * @return array{entry: JournalEntry, schedule: ScheduledTransaction, monthly: float, deferred: float}
     */
    private function defer(
        float $amount,
        int $months,
        string $startsOn,
        string $description,
        int $operatingAccountId,
        int $holdingAccountId,
        bool $deferralDebitsOperating,
    ): array {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('There is nothing to defer: the amount must be more than zero.');
        }

        if ($months < 1) {
            throw new InvalidArgumentException('A deferral has to be spread over at least one month.');
        }

        // Guarded rather than merely improbable: a schedule of 600 monthly postings is a job that runs for
        // fifty years, and the ordinary cause of one is a figure typed into the months field.
        if ($months > 120) {
            throw new InvalidArgumentException(
                "A deferral over {$months} months is almost certainly a typing mistake. Ten years is the "
                .'limit; a longer one is better expressed as several.'
            );
        }

        $monthly = floor($amount / $months * 100) / 100;

        if ($monthly <= 0) {
            throw new InvalidArgumentException(
                "{$amount} spread over {$months} months is less than a cent a month, so there is nothing "
                .'for the schedule to post. Defer it over fewer months.'
            );
        }

        $deferred = round($monthly * $months, 2);
        $start = Carbon::parse($startsOn);

        return TenantTransaction::run(function () use (
            $months, $monthly, $deferred, $start, $description,
            $operatingAccountId, $holdingAccountId, $deferralDebitsOperating,
        ): array {
            /*
             * The schedule first, so the entry can name it.
             *
             * Reversed from the obvious order deliberately: an entry stamped with a `source_type` and no
             * `source_id` is worse than one with neither — `LedgerDimensions` and the register's edit guard
             * both resolve the pair, and half of it resolves to nothing while looking deliberate. With the
             * schedule created first, the deferral entry points at the schedule that will undo it, which is
             * also the answer to "what is this 12,000 entry?" a year later.
             */
            $schedule = ScheduledTransaction::create([
                'name' => "Recognise: {$description}",
                'memo' => "{$description} — one month of ".number_format($deferred, 2)
                    ." deferred over {$months} months",
                'entry_type' => 'general',
                'interval_months' => 1,
                // The last day of the month, which is when a month's share is earned. `day_of_month` is a
                // number, so 31 is the way to say "the end" — `ScheduledTransactionService` clamps it to
                // whatever the month actually has.
                'day_of_month' => 31,
                'starts_on' => $start->toDateString(),
                // Inclusive of the last month, so `months` postings are raised and not one more.
                'ends_on' => $start->copy()->addMonthsNoOverflow($months - 1)->endOfMonth()->toDateString(),
                'is_active' => true,
            ]);

            // The schedule is the deferral entry backwards, a month at a time.
            $schedule->lines()->create([
                'account_id' => $deferralDebitsOperating ? $holdingAccountId : $operatingAccountId,
                'debit_amount' => $monthly,
                'description' => $description,
                'sort' => 1,
            ]);
            $schedule->lines()->create([
                'account_id' => $deferralDebitsOperating ? $operatingAccountId : $holdingAccountId,
                'credit_amount' => $monthly,
                'description' => $description,
                'sort' => 2,
            ]);

            // The entry the schedule will raise has to balance, or `raise()` silently returns null every
            // month and the liability sits there for ever. Asserted here, where the caller can be told.
            if (! $schedule->load('lines')->isBalanced()) {
                throw new RuntimeException('The recognition schedule does not balance. This is a bug.');
            }

            /*
             * And now the deferral itself, dated the end of the month before the first recognition.
             *
             * That date is the point: the amount is being taken out of the period that billed it, not given
             * to the first period that earns it. A deferral dated the same day as the first recognition would
             * leave the billing month overstated for as long as anybody looked at it.
             */
            $entry = $this->entries->create([
                'entry_date' => $start->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                'entry_type' => 'general',
                'memo' => "Deferred: {$description}",
                'source_type' => ScheduledTransaction::class,
                'source_id' => $schedule->getKey(),
            ], [
                [
                    'account_id' => $deferralDebitsOperating ? $operatingAccountId : $holdingAccountId,
                    'debit_amount' => $deferred,
                    'description' => $description,
                ],
                [
                    'account_id' => $deferralDebitsOperating ? $holdingAccountId : $operatingAccountId,
                    'credit_amount' => $deferred,
                    'description' => $description,
                ],
            ]);

            $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
            $posted = $this->entries->post($entry);

            return [
                'entry' => $posted,
                'schedule' => $schedule,
                'monthly' => $monthly,
                'deferred' => $deferred,
            ];
        });
    }

    /**
     * An account by code, refused rather than guessed.
     *
     * Every chart this application ships has 2500 and 1350 as of Phase 5, and a company that arrived before
     * them picks them up with `tenants:seed-baseline`. Creating one here instead would put an account into
     * somebody's chart as a side effect of a form submission, which is how a chart of accounts stops being
     * something a company recognises.
     */
    private function accountFor(string $code, string $what): int
    {
        $id = Account::where('code', $code)->value('id');

        if (! $id) {
            throw new RuntimeException(
                "This company's chart of accounts has no {$what} account ({$code}), so there is nowhere to "
                .'hold a deferral. Add it to the chart, or run php artisan tenants:seed-baseline to take the '
                .'shipped one.'
            );
        }

        return (int) $id;
    }
}
