<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Loan;
use App\Modules\Accounting\Models\LoanInstalment;
use App\Modules\Accounting\Services\GeneralLedgerService;
use App\Support\Reporting\ReportShapes;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The loan book, across every loan — `docs/reports-expansion-plan.md` Phase 1.6.
 *
 * `LoanService::generateSchedule()` and everything built on it were rendered in exactly one place: the
 * `ScheduleRelationManager` inside a single loan. So a company could read any one amortisation table and
 * could not answer "what do we owe" — the plan's words are "there is no portfolio view".
 *
 * **It foots against the liability accounts, which is the point of it living in Accounting.** Phase 2 is
 * the phase of reports that reconcile, and its rule is stated there: "each report's test asserts the
 * reconciliation … a row-count assertion proves nothing here." This report is a Phase 1 item that happens
 * to be able to make that claim already, because the schedule and the ledger are in the same database — so
 * it makes it. The record row states what the schedules say is left *and* what the accounts say, and the
 * note says whether they agree.
 *
 * That difference is the report's most valuable figure. A schedule and a liability account drift apart for
 * real reasons — an instalment paid outside the application, a manual entry against the account, a loan
 * restructured without rebuilding its table — and every one of those is something somebody needs to know.
 */
class LoanReports
{
    use ReportShapes;

    /** How far ahead "coming due" looks. Twelve months, because that is the note a balance sheet carries. */
    private const HORIZON_MONTHS = 12;

    /**
     * Every active loan: what is left, what interest is still to come, and the next year's instalments.
     *
     * **One query for the instalments, not one per loan.** `Loan::scheduledOutstanding()` and
     * `totalInterest()` are each a query, and `nextDue()` a third; over a loan book that is three queries
     * per row for figures that come out of one table. They stay for the per-loan screen, where the loan is
     * already loaded and the count is one.
     */
    public function outstanding(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $horizon = $date->copy()->addMonths(self::HORIZON_MONTHS)->toDateString();

        /** @var Collection<int, Loan> $loans */
        $loans = Loan::query()->active()->with('liabilityAccount')->orderBy('name')->get();

        if ($loans->isEmpty()) {
            return $this->emptyReport($asOf);
        }

        $instalments = LoanInstalment::query()
            ->whereIn('loan_id', $loans->modelKeys())
            ->orderBy('number')
            ->get()
            ->groupBy('loan_id');

        $rows = [];
        $outstanding = 0.0;
        $toCome = 0.0;
        $nextYear = 0.0;

        foreach ($loans as $loan) {
            /** @var Collection<int, LoanInstalment> $schedule */
            $schedule = $instalments->get($loan->getKey()) ?? collect();

            $recorded = $schedule->filter(fn (LoanInstalment $row): bool => $row->journal_entry_id !== null);
            $unrecorded = $schedule->reject(fn (LoanInstalment $row): bool => $row->journal_entry_id !== null);

            // The last *recorded* instalment's closing balance, which is what the agreement says is left.
            // Falls back to the principal for a loan nothing has been paid on — not to nought, which would
            // report a brand-new loan as settled.
            $left = round((float) ($recorded->last()->closing_balance ?? $loan->principal), 2);

            $interest = round($unrecorded->sum(fn (LoanInstalment $row): float => (float) $row->interest), 2);

            $due = round($unrecorded
                ->filter(fn (LoanInstalment $row): bool => $row->due_on !== null
                    && $row->due_on->toDateString() <= $horizon)
                ->sum(fn (LoanInstalment $row): float => (float) $row->payment), 2);

            $next = $unrecorded->first();

            $rows[] = [
                (string) $loan->name.($loan->lender ? ' · '.$loan->lender : ''),
                number_format($left, 0),
                number_format($interest, 0),
                number_format($due, 0),
                // Paid against the term, so a reader can see how far through the loan is without doing the
                // division. A schedule with no rows at all reads as such rather than as "0 of 0".
                $schedule->isEmpty() ? 'No schedule' : $recorded->count().' of '.$schedule->count(),
                $next?->due_on?->toDateString() ?? 'Settled',
            ];

            $outstanding += $left;
            $toCome += $interest;
            $nextYear += $due;
        }

        // What the accounts say, against what the schedules say. Distinct, because two loans may share a
        // liability account and its balance must not then be counted twice.
        $accountIds = $loans->pluck('liability_account_id')->filter()->unique()->values()->all();
        $ledger = round(array_sum(app(GeneralLedgerService::class)->balancesFor($accountIds, $asOf)), 2);
        $difference = round($outstanding - $ledger, 2);

        return $this->table(
            'LoansOutstanding',
            'Loans Outstanding',
            $this->subtitle('as at '.$asOf),
            ['Loan', 'Outstanding', 'Interest to come', 'Due in 12 months', 'Instalments', 'Next due'],
            'minmax(0, 1fr) 10rem 10rem 10rem 9rem 9rem',
            [1, 2, 3],
            $rows,
            [
                ['label' => 'OUTSTANDING', 'value' => round($outstanding, 2), 'accent' => true],
                // The ledger figure beside it rather than in the note, because the comparison is the
                // report: a reader should not have to hold one of the two numbers in their head.
                ['label' => 'LIABILITY ACCOUNTS', 'value' => $ledger, 'accent' => false],
            ],
            mb_strtoupper(sprintf(
                '%d loans · %s',
                $loans->count(),
                // Nought is the answer that needs saying out loud, because it is the one a reader would
                // otherwise have to work out from two figures that look similar.
                abs($difference) < 0.01
                    ? 'the schedules agree with the liability accounts'
                    : 'the schedules and the accounts differ by '.number_format(abs($difference), 2)
                        .' — an instalment paid outside the application, or a manual entry',
            )),
            [
                'Total — '.$loans->count().' loans',
                number_format($outstanding, 0),
                number_format($toCome, 0),
                number_format($nextYear, 0),
                '',
                '',
            ],
            'No active loan.',
        );
    }

    /** A company with no loans, said plainly — and with the balanced flag untouched. */
    private function emptyReport(string $asOf): array
    {
        return $this->table(
            'LoansOutstanding',
            'Loans Outstanding',
            $this->subtitle('as at '.$asOf),
            ['Loan', 'Outstanding', 'Interest to come', 'Due in 12 months', 'Instalments', 'Next due'],
            'minmax(0, 1fr) 10rem 10rem 10rem 9rem 9rem',
            [1, 2, 3],
            [],
            [
                ['label' => 'OUTSTANDING', 'value' => 0.0, 'accent' => true],
                ['label' => 'LIABILITY ACCOUNTS', 'value' => 0.0, 'accent' => false],
            ],
            'NO ACTIVE LOAN',
            null,
            'No active loan. A loan that has been settled or deactivated is not shown here.',
        );
    }
}
