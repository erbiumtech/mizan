<?php

namespace App\Modules\Advances\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\GeneralLedgerService;
use App\Modules\Advances\Models\Advance;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Collection;

/**
 * What staff owe the company — `docs/reports-expansion-plan.md` Phase 2.7.
 *
 * "A receivable from staff; feeds final settlement, so a wrong figure leaves the company out of pocket."
 * The register existed as a resource with a row per advance; what it could not answer is the total, which is
 * the figure that belongs on a balance sheet and the one a settlement is checked against.
 *
 * **A row per advance, not per employee**, which is a departure from the plan's wording and deliberate. The
 * instalment and the months remaining are properties of an *advance*: two advances with different
 * instalments cannot be summarised into one "months remaining" without inventing a figure, and somebody
 * chasing a repayment needs to know which of the two is nearly finished. The employee is named on every row
 * and the total is per company, so nothing is lost.
 *
 * **The recovered figure is summed from the loaded relation rather than asked per advance.**
 * `Advance::recoveredAmount()` runs a query each time, which over a register is one per row; with no payslip
 * to exclude it is exactly `sum(amount)` over the recoveries, and `AdvanceReportTest` asserts the two agree
 * advance by advance rather than assuming it.
 */
class AdvanceReports
{
    use ReportShapes;

    /**
     * The account advances are held in.
     *
     * `1200` is the code `config('accounting.payroll_accounts.employee_advances')` maps and the one a
     * payslip's recovery credits. Read through the config rather than written here so a company that has
     * remapped it is reconciled against its own account — and `PayrollAccounts` is not used because it
     * throws for a missing mapping, which is right before posting an entry and wrong in a report.
     */
    private function advancesAccountId(): ?int
    {
        // Guarded, and this is the guard `ModuleBoundaryTest` asks for in return for the `advances ->
        // accounting` coupling. `advances` requires `employees` and `payroll` and deliberately not
        // accounting — the same position Expenses takes, and for the reason its entry in that list gives:
        // "requiring it would make the module unsellable to a company that keeps its books elsewhere". So a
        // company without accounting gets the register and no comparison, rather than no register.
        if (! modules()->enabled('accounting')) {
            return null;
        }

        $code = data_get(setting('accounting.payroll_accounts'), 'employee_advances')
            ?: config('accounting.payroll_accounts.employee_advances');

        if (! $code) {
            return null;
        }

        $id = Account::query()->where('code', (string) $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function outstanding(string $asOf): array
    {
        /** @var Collection<int, Advance> $advances */
        $advances = Advance::query()
            ->active()
            ->whereDate('started_on', '<=', $asOf)
            ->with(['employee.user', 'recoveries'])
            ->orderBy('employee_id')
            ->get();

        $rows = [];
        $total = 0.0;
        $recovered = 0.0;
        $outstanding = 0.0;
        $unscheduled = 0;

        foreach ($advances as $advance) {
            // From the loaded relation, so a register of forty advances is one query rather than forty-one.
            $taken = round((float) $advance->recoveries->sum('amount'), 2);
            $left = round(max(0, (float) $advance->total_amount - $taken), 2);
            $instalment = (float) $advance->monthly_instalment;

            if ($instalment <= 0) {
                $unscheduled++;
            }

            $rows[] = [
                (string) ($advance->employee?->display_label ?? 'Employee #'.$advance->employee_id)
                    .($advance->reference ? ' · '.$advance->reference : ''),
                number_format((float) $advance->total_amount, 0),
                number_format($taken, 0),
                number_format($left, 0),
                // No instalment is a dash rather than a nought: an advance with no repayment schedule is
                // one nothing will ever deduct, which is a different problem from one that deducts nought.
                $instalment > 0 ? number_format($instalment, 0) : '—',
                $this->monthsRemaining($left, $instalment),
            ];

            $total += (float) $advance->total_amount;
            $recovered += $taken;
            $outstanding += $left;
        }

        $account = $this->advancesAccountId();
        $ledger = $account === null
            ? null
            : round(array_sum(app(GeneralLedgerService::class)->balancesFor([$account], $asOf)), 2);

        return $this->table(
            'AdvancesOutstanding',
            'Advances Outstanding',
            $this->subtitle('as at '.$asOf),
            ['Employee', 'Advanced', 'Recovered', 'Outstanding', 'Instalment', 'Months left'],
            'minmax(0, 1fr) 10rem 10rem 11rem 10rem 9rem',
            [1, 2, 3, 4, 5],
            $rows,
            [
                ['label' => 'OUTSTANDING', 'value' => round($outstanding, 2), 'accent' => true],
                ['label' => 'ADVANCES ACCOUNT', 'value' => $ledger ?? 0.0, 'accent' => false],
            ],
            $this->outstandingNote($advances, round($outstanding, 2), $ledger, $unscheduled),
            $rows === [] ? null : [
                'Total — '.count($rows).' advances',
                number_format($total, 0),
                number_format($recovered, 0),
                number_format($outstanding, 0),
                '',
                '',
            ],
            'Nobody has an advance outstanding.',
        );
    }

    /**
     * How long until it is cleared, at the current instalment.
     *
     * Rounded up, because a part month is still a month somebody is repaying in. A dash where there is no
     * instalment: dividing by nought is not the problem — claiming a number of months for a repayment
     * nothing deducts is.
     */
    private function monthsRemaining(float $outstanding, float $instalment): string
    {
        if ($instalment <= 0) {
            return '—';
        }

        return $outstanding <= 0 ? '0' : (string) (int) ceil($outstanding / $instalment);
    }

    /**
     * Whether the register agrees with the account, and the reason it usually will not.
     *
     * **Nothing posts an advance when it is entered.** The register records that money was lent; the ledger
     * only learns of it if somebody also recorded the payment out, and a payslip's recovery *credits* the
     * account. So a company that pays advances from the bank without booking them against this account has
     * every advance here and only the recoveries there — which shows as the account holding less than the
     * register by roughly the total ever advanced. That is the first thing to say, because it is the
     * commonest and it is nobody's mistake.
     *
     * The same shape as Phase 2.5's finding about asset cost, and worth stating in the same words: a
     * register is not a posting.
     *
     * @param  Collection<int, Advance>  $advances
     */
    private function outstandingNote(Collection $advances, float $outstanding, ?float $ledger, int $unscheduled): string
    {
        if ($advances->isEmpty()) {
            return 'NOBODY HAS AN ADVANCE OUTSTANDING';
        }

        $flags = array_filter([
            $advances->count().' advances',
            $unscheduled > 0
                ? $unscheduled.($unscheduled === 1 ? ' has' : ' have').' no instalment, so nothing deducts them'
                : null,
        ]);

        if ($ledger === null) {
            // No account to compare against — either no accounting module or no mapping. Said rather than
            // shown as a nought, which would read as an account holding nothing.
            return mb_strtoupper(implode(' · ', [...$flags, 'no advances account is available, so there is nothing to compare against']));
        }

        $difference = round($outstanding - $ledger, 2);

        $reconciliation = match (true) {
            abs($difference) < 0.01 => 'the register agrees with the advances account',
            // The signature of unposted disbursements: the account is short by about what has been lent.
            $difference > 0 => sprintf(
                'the account holds %s less than the register — nothing posts an advance when it is '
                .'entered, so this is what has been lent without a payment booked against the account',
                number_format($difference, 0),
            ),
            default => sprintf(
                'the account holds %s more than the register — a payment booked to it that is not an '
                .'advance, or an advance settled without its recovery being recorded',
                number_format(abs($difference), 0),
            ),
        };

        return mb_strtoupper(implode(' · ', [...$flags, $reconciliation]));
    }
}
