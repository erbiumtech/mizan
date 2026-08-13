<?php

namespace App\Modules\Timesheets\Services;

use App\Modules\Billing\Models\BillingRun;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Models\TimesheetEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turning a month of booked time into invoice lines.
 *
 * Lives in Timesheets rather than Billing, and that direction matters: Billing asks
 * for the lines through the container behind a `modules()->enabled('timesheets')`
 * guard, so Billing does not import Timesheets and stays sellable without it. The
 * dependency points the way the licence does.
 *
 * One line per employee per project, not one per entry. An invoice reading "Ali Raza —
 * Migration: 37.5 hours at 8,000" is a line a client can check; forty lines of two
 * hours each is a document nobody reads.
 */
class BillableHours
{
    public function __construct(private readonly TimesheetService $timesheets) {}

    /**
     * Invoice lines for everything billable in the run's month.
     *
     * @return array<int, array{description: string, amount: float}>
     */
    public function linesFor(BillingRun $run): array
    {
        $priced = $this->priceFor($run);

        return $priced['lines'];
    }

    /**
     * The lines, the entries behind them, and what could not be priced.
     *
     * Returned together because the caller building an invoice needs the entries in
     * order to lock them, and the caller previewing one needs the unpriced list in
     * order to say what was left out. Two methods would either query twice or let a
     * preview lock rows.
     *
     * @return array{
     *     lines: array<int, array{description: string, amount: float}>,
     *     entries: Collection<int, TimesheetEntry>,
     *     unpriced: array<int, string>
     * }
     */
    public function priceFor(BillingRun $run): array
    {
        $period = $this->periodFor($run);

        // The run bills one contact; the projects billable to it are those that name it
        // as their client. A project with no contact is internal and is never billed.
        $projects = Project::query()
            ->where('contact_id', $run->contact_id)
            ->get();

        $lines = [];
        $unpriced = [];
        $billed = new Collection;

        foreach ($projects as $project) {
            $entries = $this->timesheets->billableFor($project, $period['year'], $period['month']);

            foreach ($entries->groupBy('employee_id') as $employeeEntries) {
                $employee = $employeeEntries->first()->employee;

                if (! $employee) {
                    continue;
                }

                $minutes = (int) $employeeEntries->sum('minutes');
                $rate = $this->timesheets->rateFor($employee, $project);

                if ($rate === null) {
                    // Named, never silently dropped and never billed at a guess. Forty
                    // hours missing from an invoice has to be visible to whoever sends
                    // it.
                    $unpriced[] = sprintf(
                        '%s on %s: %s hours, no rate set on the project, the employee or the company default.',
                        $employee->display_label,
                        $project->name,
                        round($minutes / 60, 2),
                    );

                    continue;
                }

                $hours = round($minutes / 60, 2);

                $lines[] = [
                    'description' => sprintf(
                        '%s — %s: %s hours at %s',
                        $project->name,
                        $employee->display_label,
                        $hours,
                        number_format($rate, 2),
                    ),
                    'amount' => round($hours * $rate, 2),
                ];

                $billed = $billed->merge($employeeEntries);
            }
        }

        return ['lines' => $lines, 'entries' => $billed, 'unpriced' => $unpriced];
    }

    /**
     * Lock everything this run billed, so no hour reaches a second invoice.
     *
     * Called when the invoice is BUILT, never when the breakdown is previewed — a
     * clerk looking at next month's figures must not burn the hours.
     */
    public function lockFor(BillingRun $run): int
    {
        $entries = $this->priceFor($run)['entries'];

        $this->timesheets->lock($entries);

        return $entries->count();
    }

    /** @return array{year: int, month: int} */
    private function periodFor(BillingRun $run): array
    {
        $start = Carbon::parse($run->periodStart());

        return ['year' => $start->year, 'month' => $start->month];
    }
}
