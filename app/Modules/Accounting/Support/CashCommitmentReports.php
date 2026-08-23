<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\BeneficiarySubscription;
use App\Modules\Accounting\Models\ScheduledTransactionLine;
use App\Modules\Accounting\Services\ScheduledTransactionService;
use App\Modules\Accounting\Services\SubscriptionBillingService;
use App\Support\CashCommitments;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;

/**
 * What will hit the bank in the next ninety days — `docs/reports-expansion-plan.md` Phase 1.7.
 *
 * The plan's note on this one is "nothing in the application answers this today", and it was right: the
 * scheduled entries, the beneficiary subscriptions and the recurring invoices each had a runner that raised
 * them and no screen that listed what was coming. A company could see everything it had already been billed
 * for and nothing it had committed to.
 *
 * **Every row is a commitment, not a certainty**, and the report says which are already raised. An unraised
 * recurring invoice can be cancelled and a scheduled entry can be edited; a report that presented the lot
 * as fact would be a forecast the ledger had to honour. The distinction is a column, because the two
 * populations are read differently: what is raised is a payable somebody can chase, and what is not is a
 * decision still open.
 *
 * **The sources are asked, not imported.** Recurring invoices belong to Invoicing, and
 * `docs/module-packaging-plan.md` §8 spent a phase removing `accounting -> invoicing` — so each module
 * registers what it knows with `App\Support\CashCommitments` and this report reads the registry. A company
 * without invoicing gets a shorter list rather than an error, and the report never learns the name of a
 * module the company has not bought.
 */
class CashCommitmentReports
{
    use ReportShapes;

    /** Ninety days, which is the horizon the plan names and the one a cash-flow conversation uses. */
    private const HORIZON_DAYS = 90;

    /**
     * The timeline, soonest first.
     *
     * **Forward from the date, not a period around it.** This is the one report in the set that is about
     * what has not happened yet, so a from-and-to would invite it to be asked of the past — where every row
     * would either have been raised or quietly missed, and the answer would be a list of things to feel bad
     * about rather than a list to act on.
     */
    public function commitments(string $asOf): array
    {
        $from = Carbon::parse($asOf)->toDateString();
        $to = Carbon::parse($asOf)->addDays(self::HORIZON_DAYS)->toDateString();

        $rows = CashCommitments::between($from, $to);

        $out = array_values(array_filter($rows, fn (array $row): bool => $row['direction'] === 'out'));
        $in = array_values(array_filter($rows, fn (array $row): bool => $row['direction'] === 'in'));

        $leaving = round(array_sum(array_column($out, 'amount')), 2);
        $arriving = round(array_sum(array_column($in, 'amount')), 2);
        $unraised = array_values(array_filter($rows, fn (array $row): bool => ! $row['raised']));

        return $this->table(
            'CashCommitments',
            'Cash Commitments',
            $this->subtitle('committed between '.$from.' and '.$to),
            ['Date', 'Commitment', 'Kind', 'Direction', 'Amount', 'Raised'],
            '7rem minmax(0, 1fr) 10rem 7rem 10rem 8rem',
            [4],
            array_map(fn (array $row): array => [
                $row['date'],
                $row['description'],
                $row['kind'],
                // In words rather than a sign. A column of amounts where some are negative and some are
                // not gets added up wrongly by hand, every time.
                $row['direction'] === 'out' ? 'Out' : 'In',
                number_format((float) $row['amount'], 0),
                $row['raised'] ? 'Yes' : 'Not yet',
            ], $rows),
            [
                ['label' => 'LEAVING', 'value' => $leaving, 'accent' => true],
                // Both directions, because the question is what the bank balance does and one side of it
                // answers half. Recurring invoices are money coming in.
                ['label' => 'ARRIVING', 'value' => $arriving, 'accent' => false],
            ],
            $rows === []
                ? 'NOTHING IS COMMITTED IN THE NEXT '.self::HORIZON_DAYS.' DAYS'
                : mb_strtoupper(sprintf(
                    '%d commitments · net %s · %d not yet raised',
                    count($rows),
                    number_format($arriving - $leaving, 0),
                    count($unraised),
                )),
            [
                'Total — '.count($rows).' commitments',
                '',
                '',
                '',
                // Net, on the same row as the amounts, because a column mixing both directions has no
                // meaningful sum and a total that added them together would be nonsense.
                number_format($arriving - $leaving, 0),
                '',
            ],
            'Nothing is committed in the next '.self::HORIZON_DAYS.' days.',
        );
    }

    /**
     * Accounting's own two sources, registered with the shared registry.
     *
     * Called from `AccountingServiceProvider`. Invoicing registers its recurring invoices separately, and a
     * later phase can add loan instalments or a payroll month here without this report learning anything
     * new — which is the point of the registry rather than three method calls.
     */
    public static function registerSources(): void
    {
        CashCommitments::register('scheduled-entry', function (string $from, string $to): array {
            return array_map(fn (array $row): array => [
                'date' => $row['date']->toDateString(),
                'kind' => 'Scheduled entry',
                'description' => (string) $row['schedule']->name,
                // The debit side, which is what a scheduled entry moves out. A scheduled entry is balanced
                // by construction, so either side is the amount and the debit is the one that reads as
                // "what this costs".
                'amount' => round($row['schedule']->lines
                    ->sum(fn (ScheduledTransactionLine $line): float => (float) $line->debit_amount), 2),
                'direction' => 'out',
                'raised' => $row['raised'],
            ], app(ScheduledTransactionService::class)->occurrencesBetween($from, $to));
        });

        CashCommitments::register('subscription', function (string $from, string $to): array {
            $service = app(SubscriptionBillingService::class);
            $rows = [];

            // Illuminate's Carbon, not Carbon's own: `due()` type-hints the Laravel subclass, and an
            // instance of the parent is not an instance of the child — which fails at the call rather
            // than at the boundary, so it reached a rendered page before a test caught it.
            // Month by month, because a subscription is a monthly agreement and `due()` answers for one
            // period. Ninety days is four calendar months at most, so this is four passes and not a loop
            // whose length depends on the data.
            foreach (self::monthsBetween($from, $to) as $period) {
                foreach ($service->due($period) as $subscription) {
                    /** @var BeneficiarySubscription $subscription */
                    $date = $period->copy()->day(min((int) $subscription->due_day, $period->daysInMonth));

                    if ($date->toDateString() < $from || $date->toDateString() > $to) {
                        continue;
                    }

                    $rows[] = [
                        'date' => $date->toDateString(),
                        'kind' => 'Subscription',
                        'description' => trim(($subscription->beneficiary?->name ?? 'Unknown beneficiary')
                            .' · '.$subscription->description, ' ·'),
                        'amount' => round((float) $subscription->amount, 2),
                        'direction' => 'out',
                        'raised' => $service->alreadyBilled($subscription, $period),
                    ];
                }
            }

            return $rows;
        });
    }

    /**
     * The calendar months a window touches, as first-of-month dates.
     *
     * @return array<int, Carbon>
     */
    private static function monthsBetween(string $from, string $to): array
    {
        $months = [];
        $cursor = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = $cursor->copy();
            $cursor = $cursor->addMonth();
        }

        return $months;
    }
}
