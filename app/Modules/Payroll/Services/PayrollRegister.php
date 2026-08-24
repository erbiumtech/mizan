<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\PayrollAccounts;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponent;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Employee by pay component for one month, and what the ledger says about it.
 *
 * `docs/reports-expansion-plan.md` Phase 2.1 — "the single most-asked-for payroll report; today only
 * per-payslip views exist".
 *
 * **Built from `payslip_pay_components` alone, which is only correct because of what
 * `PayComponentRecorder` does.** Half of pay in this application lives in payslip *columns*
 * (`basic_wage`, `withholding_tax`, …) and half in component rows; the shipped components are all
 * `is_column_backed`. The recorder copies the columns into component rows on every save, so the component
 * table is the complete record of what a payslip paid — and `PayComponentSeeder`'s own comment says that is
 * the point: "a report, a statement or a form can ask what pay is made of instead of carrying its own list,
 * which is how the billing statement came to keep a hand-maintained column map with an 'Other' bucket."
 * This report does not keep one.
 *
 * **A component that has since been deactivated still gets a column**, if any payslip in the month paid it.
 * Reading only `active()` components would drop the amount from the columns and leave it in the row total,
 * so the register would stop adding up — silently, and only for the months where somebody had tidied up.
 *
 * **The ledger side is the point of the report.** Phase 2's rule is that each of its reports carries a
 * record row tying to a ledger balance, and here the tie is exact: the payroll entry credits *salaries
 * payable* with the payslip's net salary, so the register's net total must equal that credit across the
 * month's posted payslips. What breaks the tie in practice is an *unposted* payslip — the register counts
 * it, the ledger does not — which is why that count is returned rather than left for a reader to work out.
 */
class PayrollRegister
{
    /**
     * The register for a month of a fiscal year.
     *
     * @return array{
     *     month: string,
     *     fiscal_year: ?FiscalYear,
     *     components: Collection<int, PayComponent>,
     *     employees: array<int, string>,
     *     cells: array<string, float>,
     *     totals: array<int, array{earnings: float, deductions: float, net: float}>,
     *     payslips: int,
     *     unposted: int,
     *     ledger_net: float,
     *     ledger_available: bool,
     * }
     */
    public function forMonth(string $month, ?FiscalYear $fiscalYear): array
    {
        $payslips = Payslip::query()
            ->where('month', $month)
            ->when($fiscalYear, fn ($query) => $query->where('fiscal_year_id', $fiscalYear->getKey()))
            ->with('employee.user')
            ->get();

        if ($payslips->isEmpty()) {
            return $this->empty($month, $fiscalYear);
        }

        // One query for every cell in the grid.
        $rows = PayslipComponent::query()
            ->whereIn('payslip_id', $payslips->modelKeys())
            ->get();

        $components = $this->componentsIn($rows);

        $cells = [];
        $totals = [];

        foreach ($payslips as $payslip) {
            $totals[$payslip->getKey()] = ['earnings' => 0.0, 'deductions' => 0.0, 'net' => 0.0];
        }

        $kinds = $components->mapWithKeys(fn (PayComponent $c): array => [$c->getKey() => $c->kind]);

        foreach ($rows as $row) {
            $amount = round((float) $row->amount, 2);
            $cells[$row->payslip_id.':'.$row->pay_component_id] = $amount;

            $bucket = ($kinds[$row->pay_component_id] ?? PayComponent::KIND_EARNING) === PayComponent::KIND_EARNING
                ? 'earnings'
                : 'deductions';

            $totals[$row->payslip_id][$bucket] += $amount;
        }

        foreach ($totals as $payslipId => $total) {
            // Earnings less deductions, and it equals the payslip's own `net_salary` by construction:
            // that column is `total_earnings + expense_reimbursement - total_deductions`, and summing every
            // earning component is the first two of those. Computed rather than read so the register's own
            // arithmetic is what the reader is checking, not a column they have to trust.
            $totals[$payslipId]['net'] = round($total['earnings'] - $total['deductions'], 2);
        }

        return [
            'month' => $month,
            'fiscal_year' => $fiscalYear,
            'components' => $components,
            'employees' => $payslips
                ->mapWithKeys(fn (Payslip $p): array => [
                    // Keyed on the payslip rather than the employee. `payslips` carries a unique key on
                    // (employee, month, fiscal year), so the two are one-to-one here and either would do —
                    // but the cells come from `payslip_pay_components`, which is keyed on the payslip, and
                    // matching that means no second lookup and no chance of the two keys diverging.
                    $p->getKey() => $p->employee?->display_label ?? 'Employee #'.$p->employee_id,
                ])
                ->all(),
            'cells' => $cells,
            'totals' => $totals,
            'payslips' => $payslips->count(),
            'unposted' => $this->unpostedCount($payslips),
            ...$this->ledgerNet($payslips),
        ];
    }

    /**
     * Every component any payslip in the month paid, in reading order.
     *
     * Earnings before deductions and each by its own `sort`, which is the order a payslip is laid out in —
     * a register whose columns ran in a different order from the payslip they summarise is two documents
     * about one month that cannot be read side by side.
     *
     * @param  Collection<int, PayslipComponent>  $rows
     * @return Collection<int, PayComponent>
     */
    private function componentsIn(Collection $rows): Collection
    {
        $ids = $rows->pluck('pay_component_id')->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return PayComponent::query()
            ->whereKey($ids->all())
            ->get()
            // One computed key rather than `sortBy([...])`'s multi-comparator form, which is easy to get
            // subtly wrong: kind first, then the component's own sort, then the label to break a tie.
            ->sortBy(fn (PayComponent $component): string => sprintf(
                '%d-%06d-%s',
                $component->isEarning() ? 0 : 1,
                (int) $component->sort,
                (string) $component->label,
            ))
            ->values();
    }

    /**
     * Payslips with no posted journal entry against them.
     *
     * The everyday reason the register and the ledger disagree, and the one thing that makes the difference
     * explicable rather than alarming. A submitted-but-unapproved entry counts as unposted here, because it
     * has moved no balance.
     *
     * @param  Collection<int, Payslip>  $payslips
     */
    private function unpostedCount(Collection $payslips): int
    {
        $posted = JournalEntry::query()
            ->forSource(Payslip::class)
            ->whereIn('source_id', $payslips->modelKeys())
            ->where('is_posted', true)
            ->pluck('source_id')
            ->unique();

        return $payslips->count() - $posted->count();
    }

    /**
     * What the ledger says the month's net pay was.
     *
     * The credit to *salaries payable* from the payslips' own posted entries — not the account's balance,
     * which would also carry last month's unpaid salaries and every payment made against them. Two
     * independent statements of one figure is the whole point; a balance would be neither independent nor
     * the same figure.
     *
     * `ledger_available` is false where the mapping cannot be resolved at all. `PayrollAccounts::id()`
     * throws for an unmapped key or a code that names no account, which is right when it is about to post
     * an entry and wrong in a report: a company whose settings are half-filled should read "the ledger
     * comparison is unavailable", not a stack trace.
     *
     * @param  Collection<int, Payslip>  $payslips
     * @return array{ledger_net: float, ledger_available: bool}
     */
    private function ledgerNet(Collection $payslips): array
    {
        try {
            $accountId = PayrollAccounts::id('salaries_payable');
        } catch (Throwable) {
            return ['ledger_net' => 0.0, 'ledger_available' => false];
        }

        $credits = JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', function ($query) use ($payslips): void {
                $query->where('is_posted', true)
                    ->forSource(Payslip::class)
                    ->whereIn('source_id', $payslips->modelKeys());
            })
            ->sum('credit_amount');

        return ['ledger_net' => round((float) $credits, 2), 'ledger_available' => true];
    }

    /** @return array<string, mixed> */
    private function empty(string $month, ?FiscalYear $fiscalYear): array
    {
        return [
            'month' => $month,
            'fiscal_year' => $fiscalYear,
            'components' => collect(),
            'employees' => [],
            'cells' => [],
            'totals' => [],
            'payslips' => 0,
            'unposted' => 0,
            'ledger_net' => 0.0,
            'ledger_available' => true,
        ];
    }
}
