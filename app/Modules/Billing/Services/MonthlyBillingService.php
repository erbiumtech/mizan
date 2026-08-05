<?php

namespace App\Modules\Billing\Services;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Advances\Models\AdvanceRecovery;
use App\Modules\Billing\Models\BillingRun;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Payroll\Models\Payslip;
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
     * The employee columns of the client's statement, in the order the client
     * reads them, keyed by the payslip column each is drawn from.
     *
     * `bonus` and `other` are held back unless a month actually has them: the
     * statement is meant to look like the sheet the client is used to, which has
     * five columns. They are never dropped when there is money in them — see
     * statement(), where `other` catches any part of the gross the named columns
     * do not account for, so the row always adds up to what is billed.
     */
    public const SALARY_COLUMNS = [
        'basic_wage' => 'Basic Salary',
        'extra_work_hours' => 'Extra Work',
        'petrol_allowance' => 'Petrol Allowance',
        'medical_allowance' => 'Medical Allowance',
        'device_allowance' => 'Device Allowance',
        'bonus' => 'Bonus',
        'other' => 'Other',
    ];

    /** The five the client's sheet always carries, empty or not. */
    private const ALWAYS_SHOWN_COLUMNS = [
        'basic_wage', 'extra_work_hours', 'petrol_allowance', 'medical_allowance', 'device_allowance',
    ];

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

        $columnTotals = [];

        foreach (array_keys(self::SALARY_COLUMNS) as $column) {
            $columnTotals[$column] = round(
                array_sum(array_column(array_column($employees, 'amounts'), $column)),
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
            'columns' => array_intersect_key(
                self::SALARY_COLUMNS,
                array_flip(array_filter(
                    array_keys(self::SALARY_COLUMNS),
                    fn (string $column): bool => in_array($column, self::ALWAYS_SHOWN_COLUMNS, true)
                        || $columnTotals[$column] != 0.0,
                )),
            ),
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
        $expenses = $this->expenseLines($run);
        $credits = $this->creditLines($run);

        $salaryTotal = $this->sum($salaries);
        $expenseTotal = $this->sum($expenses);
        $creditTotal = $this->sum($credits);

        return [
            'salaries' => $salaries,
            'expenses' => $expenses,
            'credits' => $credits,
            'salary_total' => $salaryTotal,
            'expense_total' => $expenseTotal,
            'credit_total' => $creditTotal,
            'subtotal' => round($salaryTotal + $expenseTotal + $creditTotal, 2),
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
        $lines = array_merge($breakdown['salaries'], $breakdown['expenses'], $breakdown['credits']);

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

            foreach (array_keys(self::SALARY_COLUMNS) as $column) {
                $amounts[$column] = $column === 'other'
                    ? 0.0
                    : round((float) $payslip->{$column}, 2);
            }

            $amounts['other'] = round($total - array_sum($amounts), 2);

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
    protected function billablePayslips(BillingRun $run): Collection
    {
        return Payslip::with('employee.user')
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
