<?php

namespace App\Modules\Billing\Services;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Advances\Models\AdvanceRecovery;
use App\Modules\Billing\Models\BillingRun;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Contracts\BillableTime;
use App\Support\TenantTransaction;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Build the month's bill to the client.
 *
 * The bill was kept in a spreadsheet: a row per employee at full cost, the
 * month's office expenses underneath, less what employees repaid on their
 * advances, converted to the client's currency at the month's rate. Every one of
 * those figures already exists in the system — payslips, payments, the advance
 * ledger — so the bill is assembled from them rather than typed a second time.
 *
 * What comes out is an ordinary draft Invoice. Issuing it, posting it, ageing it
 * and printing it are Invoicing's job and unchanged; this only decides the lines.
 */
class MonthlyBillingService
{
    /**
     * The five the client's sheet always carries, empty or not, by component code.
     *
     * Everything else appears only in a month that has money in it, which is what keeps
     * the statement looking like the sheet the client is used to.
     */
    private const ALWAYS_SHOWN_COMPONENTS = [
        'basic_wage', 'extra_work_hours', 'petrol_allowance', 'medical_allowance', 'device_allowance',
    ];

    /**
     * Earning components paid with salary that are **not** part of the gross.
     *
     * Exactly one today, and it is not an oversight: a reimbursement is the employee's own
     * money coming back, so `PayslipService` leaves it out of `total_earnings` (see the
     * component's own comment in `PayComponentSeeder::SHIPPED`). It must be left out here too
     * or every row would be billed higher than the payslip it came from.
     *
     * Named here rather than flagged on `pay_components`, deliberately. A column saying
     * "counts toward gross" that `PayslipService` did not read would be a second source of
     * truth for the same fact, and the two would drift — which is the failure this whole
     * refactor is undoing. `BillingStatementTest` asserts each row sums to `total_earnings`,
     * so if that formula ever changes, this list fails loudly rather than quietly mis-billing.
     */
    private const NOT_IN_GROSS = ['expense_reimbursement'];

    /** Not a component: the reconciling residual. See `employeeRows()`. */
    private const RESIDUAL_COLUMN = 'other';

    /**
     * The bill as the client reads it: a row per employee broken into what makes
     * up their cost, the office expenses under it, then the credits and the
     * conversion.
     *
     * The same figures as breakdown() — this is that bill set out in columns
     * rather than as invoice lines, and the two totals are asserted equal in
     * BillingStatementTest. breakdown() stays the source for the invoice, whose
     * lines are one per employee: an invoice line has one amount, not six.
     *
     * @return array{
     *     columns: array<string, string>,
     *     employees: array<int, array{name: string, code: string, amounts: array<string, float>, total: float}>,
     *     column_totals: array<string, float>,
     *     salary_total: float,
     *     expenses: array<int, array{description: string, amount: float}>,
     *     expense_total: float,
     *     credits: array<int, array{description: string, amount: float}>,
     *     credit_total: float,
     *     subtotal: float,
     *     client_total: float|null,
     * }
     */
    public function statement(BillingRun $run): array
    {
        $employees = $this->employeeRows($run);
        $columns = $this->salaryColumns($employees);

        // Every displayed column present on every row. The view indexes
        // `$employee['amounts'][$key]` for each column it draws, so an employee who was
        // never paid a component another employee was would otherwise be a missing key.
        foreach ($employees as $index => $employee) {
            foreach (array_keys($columns) as $code) {
                $employees[$index]['amounts'][$code] = $employee['amounts'][$code] ?? 0.0;
            }
        }

        $columnTotals = [];

        foreach (array_keys($columns) as $code) {
            $columnTotals[$code] = round(
                array_sum(array_column(array_column($employees, 'amounts'), $code)),
                2,
            );
        }

        // Itemised, not grouped: the invoice bills "Utilities", the statement
        // lists what the utilities were. Same payments, same total.
        $expenses = $this->expenseItems($run);
        $credits = $this->creditLines($run);

        $salaryTotal = round(array_sum(array_column($employees, 'total')), 2);
        $expenseTotal = $this->sum($expenses);
        $creditTotal = $this->sum($credits);
        $subtotal = round($salaryTotal + $expenseTotal + $creditTotal, 2);

        $rate = (float) $run->exchange_rate;

        return [
            'columns' => $columns,
            'employees' => $employees,
            'column_totals' => $columnTotals,
            'salary_total' => $salaryTotal,
            'expenses' => $expenses,
            'expense_total' => $expenseTotal,
            'credits' => $credits,
            'credit_total' => $creditTotal,
            'subtotal' => $subtotal,
            'client_total' => $rate > 0 ? round($subtotal / $rate, 2) : null,
        ];
    }

    /**
     * What the month's invoice would contain, without writing anything.
     *
     * @return array{
     *     salaries: array<int, array{description: string, amount: float}>,
     *     expenses: array<int, array{description: string, amount: float}>,
     *     credits: array<int, array{description: string, amount: float}>,
     *     salary_total: float, expense_total: float, credit_total: float, subtotal: float
     * }
     */
    public function breakdown(BillingRun $run): array
    {
        $salaries = $this->salaryLines($run);
        $hours = $this->hoursLines($run);
        $expenses = $this->expenseLines($run);
        $credits = $this->creditLines($run);

        $salaryTotal = $this->sum($salaries);
        $hoursTotal = $this->sum($hours);
        $expenseTotal = $this->sum($expenses);
        $creditTotal = $this->sum($credits);

        return [
            'salaries' => $salaries,
            // Empty for every company without `timesheets`, which is what keeps a
            // headcount-billed client's invoice byte-identical to before phase 4.
            'hours' => $hours,
            'expenses' => $expenses,
            'credits' => $credits,
            'salary_total' => $salaryTotal,
            'hours_total' => $hoursTotal,
            'expense_total' => $expenseTotal,
            'credit_total' => $creditTotal,
            'subtotal' => round($salaryTotal + $hoursTotal + $expenseTotal + $creditTotal, 2),
        ];
    }

    /**
     * Create or rebuild the run's draft invoice.
     *
     * Rebuilding replaces the lines rather than adding to them: a month is
     * normally billed before the last few expenses are entered, and appending
     * would bill the earlier ones twice.
     */
    public function build(BillingRun $run): Invoice
    {
        if (! $run->isRebuildable()) {
            throw new InvalidArgumentException(
                "{$run->invoice->invoice_number} has already been issued and cannot be rebuilt."
            );
        }

        $breakdown = $this->breakdown($run);
        $lines = array_merge(
            $breakdown['salaries'],
            $breakdown['hours'] ?? [],
            $breakdown['expenses'],
            $breakdown['credits'],
        );

        if ($lines === []) {
            throw new InvalidArgumentException(
                "Nothing to bill for {$run->periodLabel()}: no payslips, expenses or recoveries in that month."
            );
        }

        return TenantTransaction::run(function () use ($run, $lines, $breakdown) {
            $invoice = $run->invoice ?? Invoice::create([
                'kind' => Invoice::KIND_SALE,
                'contact_id' => $run->contact_id,
                'invoice_date' => $run->invoice_date->toDateString(),
                'due_date' => $run->due_date?->toDateString(),
                'fiscal_year_id' => $run->fiscal_year_id,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
                'memo' => "Services for {$run->periodLabel()}",
            ]);

            $invoice->lines()->delete();

            foreach ($lines as $line) {
                $invoice->lines()->create([
                    'description' => $line['description'],
                    'quantity' => 1,
                    'unit_price' => $line['amount'],
                    'line_total' => $line['amount'],
                ]);
            }

            $invoice->update([
                'subtotal' => $breakdown['subtotal'],
                'total' => round($breakdown['subtotal'] + (float) $invoice->tax_amount, 2),
                'invoice_date' => $run->invoice_date->toDateString(),
                'due_date' => $run->due_date?->toDateString(),
                'memo' => "Services for {$run->periodLabel()}",
            ]);

            $run->update(['invoice_id' => $invoice->id]);

            // Locked here, inside the transaction that writes the invoice, and nowhere
            // else. Previewing a breakdown must never burn the hours — a clerk looking
            // at next month's figures would otherwise find them gone. Nothing happens
            // for a company without Timesheets: the default binding locks nothing.
            $this->billableTime()->lockFor(...$this->periodOf($run));

            return $invoice->refresh();
        });
    }

    /**
     * One line per employee, at what they cost — gross earnings, not what they
     * were paid. Tax withheld and deductions taken are settlements between the
     * company and the employee; the client funds the whole cost either way.
     *
     * @return array<int, array{description: string, amount: float}>
     */
    /**
     * Time-and-materials lines: hours × rate, per employee per project.
     *
     * Empty for a company without `timesheets` — which is why a headcount-billed client is completely
     * unaffected by that module existing. docs/hrms-plan.md §4.3 is explicit that Billing requires
     * Timesheets *for this line type only*, and this asks the `BillableTime` contract rather than naming
     * the module, so the pair is no longer a cycle. docs/module-packaging-plan.md §11.
     *
     * Two refusals rather than a guess, both made on the far side of the contract:
     *
     *  - **Time with no rate is not billed, and says so.** A made-up rate produces an
     *    invoice that looks right and charges the wrong amount. The line is skipped and
     *    named in `unpriced` so somebody sees forty hours did not make it.
     *  - **Entries are locked only when the invoice is actually built**, not when the
     *    breakdown is previewed, or a clerk looking at next month's figures would burn
     *    the hours.
     *
     * @return array<int, array{description: string, amount: float}>
     */
    protected function hoursLines(BillingRun $run): array
    {
        return $this->billableTime()->linesFor(...$this->periodOf($run));
    }

    private function billableTime(): BillableTime
    {
        return app(BillableTime::class);
    }

    /**
     * The run as the three values `BillableTime` asks for: contact, year, month.
     *
     * A contract that passed the `BillingRun` would pass this module along with it, which is the edge that
     * made billing and timesheets inextricable in the first place.
     *
     * @return array{int|string, int, int}
     */
    private function periodOf(BillingRun $run): array
    {
        $start = $run->periodStart();

        return [$run->contact_id, $start->year, $start->month];
    }

    protected function salaryLines(BillingRun $run): array
    {
        return $this->billablePayslips($run)->map(fn (Payslip $payslip): array => [
            'description' => trim(sprintf(
                'Salary — %s (%s)',
                $payslip->employee?->user?->name ?? 'Unnamed employee',
                $payslip->employee?->employee_id ?? '—'
            )),
            'amount' => round((float) $payslip->total_earnings, 2),
        ])->values()->all();
    }

    /**
     * The same employees the invoice bills, broken into the columns the client's
     * sheet shows.
     *
     * The row total is `total_earnings` — the figure the invoice line carries —
     * and never the sum of the columns, so a statement can never quietly bill a
     * different number from the invoice beside it. Anything in the gross the named
     * columns do not account for lands in `other` rather than going missing:
     * payroll composes total_earnings from these six today, and a seventh added
     * later would otherwise leave the row not adding up.
     *
     * @return array<int, array{name: string, code: string, amounts: array<string, float>, total: float}>
     */
    protected function employeeRows(BillingRun $run): array
    {
        return $this->billablePayslips($run)->map(function (Payslip $payslip): array {
            $total = round((float) $payslip->total_earnings, 2);

            $amounts = [];

            // What this payslip actually paid, component by component, rather than six named
            // columns and a bucket. `payslip_components` is written on every save by
            // PayComponentRecorder, so a data-driven allowance — the whole point of pay
            // components being data — reaches the client's statement under its own label
            // instead of appearing as an unexplained "Other".
            foreach ($payslip->components as $row) {
                $component = $row->component;

                if (! $component
                    || ! $component->isEarning()
                    || in_array($component->code, self::NOT_IN_GROSS, true)) {
                    continue;
                }

                $amounts[$component->code] = round(
                    ($amounts[$component->code] ?? 0.0) + (float) $row->amount,
                    2,
                );
            }

            // The reconciling residual, and it is no longer the same thing it was.
            //
            // It used to hold every data-driven component, because the statement only knew
            // six columns — so the feature whose whole point is that a new allowance is a row
            // reached the client as an unexplained "Other". Now the components are named, and
            // this catches only gross the components genuinely cannot account for: a payslip
            // edited in the database, or one left by an older calculation. Hidden when zero,
            // which is every ordinary month.
            //
            // Kept rather than dropped because the row has to add up to what is billed. A
            // statement whose parts silently fall short of its own total is worse than an
            // ugly column.
            $amounts[self::RESIDUAL_COLUMN] = round($total - array_sum($amounts), 2);

            return [
                'name' => $payslip->employee?->user?->name ?? 'Unnamed employee',
                'code' => $payslip->employee?->employee_id ?? '—',
                'amounts' => $amounts,
                'total' => $total,
            ];
        })->values()->all();
    }

    /**
     * The month's payslips worth billing, in the order the client reads them.
     *
     * Shared by the invoice lines and the statement so the two cannot come to
     * bill a different set of people.
     *
     * @return Collection<int, Payslip>
     */
    /**
     * Which salary columns this month's statement shows, and in what order.
     *
     * **The client's five come first, in the client's order** — which is not the components'
     * `sort` order, and that difference is deliberate. This statement is meant to look like
     * the sheet the client has been reading for years; re-ordering their columns to match an
     * internal sort field would be a change they notice and nobody asked for. Everything
     * after them is ordered by `sort`, so a new allowance lands where somebody decided it
     * should rather than wherever the first employee to receive it put it.
     *
     * **Not filtered to active components.** A company that retires an allowance in March
     * must still see it on January's statement — the month was billed with it, and a
     * statement that silently drops a column no longer in use stops adding up to the invoice
     * beside it. `is_active` governs what can be *paid* next month, not what was paid last.
     *
     * @param  array<int, array{amounts: array<string, float>}>  $employees
     * @return array<string, string> component code => label
     */
    private function salaryColumns(array $employees): array
    {
        $totals = [];

        foreach ($employees as $employee) {
            foreach ($employee['amounts'] as $code => $amount) {
                $totals[$code] = round(($totals[$code] ?? 0.0) + $amount, 2);
            }
        }

        $labels = PayComponent::query()
            ->where('kind', PayComponent::KIND_EARNING)
            ->whereNotIn('code', self::NOT_IN_GROSS)
            ->orderBy('sort')
            ->orderBy('id')
            ->pluck('label', 'code');

        $columns = [];

        // The client's five, in the client's order, whether or not this month has money in
        // them. A label from the component where there is one, so renaming "Petrol Allowance"
        // renames it here too.
        foreach (self::ALWAYS_SHOWN_COMPONENTS as $code) {
            $columns[$code] = $labels[$code] ?? $code;
        }

        // Then everything else that has money in it, in the components' own order.
        foreach ($labels as $code => $label) {
            if (! isset($columns[$code]) && ($totals[$code] ?? 0.0) != 0.0) {
                $columns[$code] = $label;
            }
        }

        // A component paid on a payslip but since deleted outright would leave money in a row
        // with no column to show it in, and the row would stop adding up. Shown under its own
        // code, which is ugly on purpose: it means somebody deleted a component that had been
        // paid, and the statement should say so rather than lose the money.
        foreach ($totals as $code => $amount) {
            if (! isset($columns[$code]) && $code !== self::RESIDUAL_COLUMN && $amount != 0.0) {
                $columns[$code] = $code;
            }
        }

        // Last, and only when it has something in it.
        if (($totals[self::RESIDUAL_COLUMN] ?? 0.0) != 0.0) {
            $columns[self::RESIDUAL_COLUMN] = 'Other';
        }

        return $columns;
    }

    protected function billablePayslips(BillingRun $run): Collection
    {
        // components.component eager-loaded: employeeRows() reads every payslip's components,
        // and a statement for forty employees would otherwise be forty queries plus one each
        // per component.
        return Payslip::with(['employee.user', 'components.component'])
            ->where('month', $run->month)
            ->where('fiscal_year_id', $run->fiscal_year_id)
            ->get()
            ->filter(fn (Payslip $payslip): bool => (float) $payslip->total_earnings > 0)
            ->sortBy(fn (Payslip $payslip): string => $payslip->employee?->user?->name ?? '')
            ->values();
    }

    /**
     * The month's office costs, one line per kind of expense.
     *
     * Read from payments rather than the ledger so the line reads as the client's
     * bill reads — "Rent", "Utilities" — and grouped so a month of small food
     * payments arrives as one figure.
     *
     * Payments made against a payslip are left out, and so are payments of the
     * salary type: those are the same money as the salary lines above.
     *
     * @return array<int, array{description: string, amount: float}>
     */
    protected function expenseLines(BillingRun $run): array
    {
        return $this->expensePayments($run)
            ->groupBy(fn (Payment $payment): string => $payment->transactionType?->name ?? 'Other expenses')
            ->map(fn ($group, string $name): array => [
                'description' => $name,
                'amount' => round((float) $group->sum('amount'), 2),
            ])
            ->filter(fn (array $line): bool => $line['amount'] != 0.0)
            ->sortBy('description')
            ->values()
            ->all();
    }

    /**
     * The same costs, one line per payment instead of per kind.
     *
     * What the client's sheet lists: "House rent", "Gas", "AC gas and kitchen
     * exhaust" — the thing that was bought, not the account it was posted to.
     * Grouping is right for an invoice line and wrong for the statement, where
     * "Utilities 236,826" is the figure somebody rings up to query.
     *
     * Same payments as expenseLines(), so the two add to the same total — pinned
     * in BillingStatementTest.
     *
     * @return array<int, array{description: string, amount: float}>
     */
    protected function expenseItems(BillingRun $run): array
    {
        return $this->expensePayments($run)
            ->sortBy([
                fn (Payment $a, Payment $b) => $a->value_date <=> $b->value_date,
                fn (Payment $a, Payment $b) => ($a->details ?? '') <=> ($b->details ?? ''),
            ])
            ->map(fn (Payment $payment): array => [
                'description' => trim((string) $payment->details) !== ''
                    ? (string) $payment->details
                    : ($payment->transactionType?->name ?? 'Other expenses'),
                'amount' => round((float) $payment->amount, 2),
            ])
            ->filter(fn (array $line): bool => $line['amount'] != 0.0)
            ->values()
            ->all();
    }

    /**
     * The month's payments that belong on the bill.
     *
     * Payments made against a payslip are left out, and so are payments of the
     * salary type: those are the same money as the employee rows.
     *
     * @return Collection<int, Payment>
     */
    protected function expensePayments(BillingRun $run): Collection
    {
        return Payment::with('transactionType')
            ->whereNull('payslip_id')
            ->whereNotNull('value_date')
            // Passed as instants, not date strings: `value_date` is a date cast and
            // holds midnight, so an upper bound of '2026-07-31' sorts before
            // '2026-07-31 00:00:00' and silently drops everything dated on the last
            // day of the month — the rent, most months.
            ->whereBetween('value_date', [$run->periodStart(), $run->periodEnd()])
            ->get()
            ->reject(fn (Payment $payment): bool => $payment->transactionType?->code === Payment::SALARY_TRANSACTION_CODE)
            ->values();
    }

    /**
     * What employees repaid on their advances this month, as a credit.
     *
     * An advance is billed to the client when it is paid out — it leaves the
     * company's bank like any other expense — so as the employee repays it out of
     * payroll the client gets it back. Without this the client funds the same
     * money twice.
     *
     * Guarded rather than declared as a requirement: a client with no advances
     * has nothing to credit, and Billing has to be sellable without the module.
     *
     * @return array<int, array{description: string, amount: float}>
     */
    protected function creditLines(BillingRun $run): array
    {
        if (! modules()->enabled('advances')) {
            return [];
        }

        $recovered = AdvanceRecovery::whereBetween(
            'recovered_on',
            [$run->periodStart(), $run->periodEnd()],
        )->sum('amount');

        $recovered = round((float) $recovered, 2);

        if ($recovered <= 0) {
            return [];
        }

        return [[
            'description' => 'Less employee advance repayments',
            'amount' => -$recovered,
        ]];
    }

    /** @param array<int, array{amount: float}> $lines */
    protected function sum(array $lines): float
    {
        return round(array_sum(array_column($lines, 'amount')), 2);
    }
}
