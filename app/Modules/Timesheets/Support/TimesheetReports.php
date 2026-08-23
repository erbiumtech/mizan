<?php

namespace App\Modules\Timesheets\Support;

use App\Modules\Timesheets\Services\TimesheetService;
use App\Support\Reporting\ReportShapes;
use Carbon\Carbon;

/**
 * The two timesheet reports — `docs/reports-expansion-plan.md` Phase 1.4.
 *
 * `utilisationFor()` and `planVersusActual()` both existed, both had tests, and neither had a caller. Both
 * answer for **one employee**, which is the part that made them unreachable rather than merely unused: the
 * question people actually ask is "how did the team's month go", and asking it of these two meant a loop.
 *
 * **Two things this file does not do, and both are deliberate.**
 *
 * It does not state a capacity, and so does not state a percentage against one. The plan asks for capacity;
 * `utilisationFor()` returns `expected_hours` as null in every branch, and its own comment says why — a
 * rule that made timesheets and attendance reconcile "would make people book the difference somewhere to
 * make the screen agree, which produces worse data than the gap it closed". A report is exactly where an
 * invented denominator would be read as fact, so *Billable share* is the ratio instead: what proportion of
 * the time somebody recorded was billable. It assumes nothing about what their month should have held.
 *
 * And it does not loop. Both figures come from the bulk methods on `TimesheetService`, which aggregate in
 * the database — three queries and four respectively, whatever the headcount. The per-employee methods stay
 * for the per-employee screens.
 */
class TimesheetReports
{
    use ReportShapes;

    /** How many project columns the matrix will draw before it starts leaving some out. */
    private const PROJECT_COLUMNS = 12;

    /**
     * Booked time per employee for the month, billable against not.
     *
     * The month rather than the year, because a timesheet is filled in weekly and reviewed monthly, and a
     * year-to-date billable share averages a bad month into eleven others.
     */
    public function utilisation(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $rows = app(TimesheetService::class)->utilisation($date->year, $date->month);

        $billable = array_sum(array_column($rows, 'billable_hours'));
        $other = array_sum(array_column($rows, 'non_billable_hours'));
        $booked = $billable + $other;

        return $this->table(
            'TimesheetUtilisation',
            'Timesheet Utilisation',
            $this->subtitle('time booked in '.$date->format('F Y')),
            ['Employee', 'Billable', 'Non-billable', 'Booked', 'Billable share', 'Projects'],
            'minmax(0, 1fr) 8rem 9rem 8rem 9rem 7rem',
            [1, 2, 3, 4, 5],
            array_map(fn (array $row): array => [
                $row['employee'],
                number_format($row['billable_hours'], 1),
                number_format($row['non_billable_hours'], 1),
                number_format($row['booked_hours'], 1),
                $row['billable_share'] === null ? '—' : number_format($row['billable_share'], 1).'%',
                number_format($row['projects']),
            ], $rows),
            [
                ['label' => 'HOURS BOOKED', 'value' => round($booked, 2), 'accent' => true],
                ['label' => 'BILLABLE HOURS', 'value' => round($billable, 2), 'accent' => false],
            ],
            $rows === []
                ? 'NOBODY BOOKED TIME IN THIS MONTH'
                : mb_strtoupper(sprintf(
                    '%d people · billable share %s · compared, never enforced',
                    count($rows),
                    $this->share($billable, $booked),
                )),
            [
                'Total — '.count($rows).' people',
                number_format($billable, 1),
                number_format($other, 1),
                number_format($booked, 1),
                $this->share($billable, $booked),
                '',
            ],
            'Nobody booked time in this month.',
        );
    }

    /**
     * Allocation against hours booked, employee by project.
     *
     * **The report that pays for the module**, in `planVersusActual()`'s own words: allocation says 50%,
     * timesheets say 20%, and that gap is what anybody asks a timesheet system for. So each cell states
     * both — booked hours over the allocation the stint promised — rather than a difference, which would
     * hide which of the two is unusual.
     *
     * Every pairing either side knows about. A project somebody is allocated to and has booked nothing
     * against is the most interesting row on the report, and time booked against a project nobody was
     * allocated to is the second. A report showing only the pairings that agree with each other would
     * always agree with itself.
     */
    public function planVersusActual(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $grid = app(TimesheetService::class)->allocationGrid($date->year, $date->month);

        // Busiest projects first, so the columns that get dropped are the quiet ones.
        $ranked = collect($grid['projects'])
            ->map(fn (string $name, int|string $id): array => [
                'id' => $id,
                'name' => $name,
                'hours' => collect($grid['cells'])
                    ->filter(fn (array $cell, string $key): bool => str_ends_with($key, ':'.$id))
                    ->sum('booked_hours'),
            ])
            ->sortByDesc('hours')
            ->values();

        $shown = $ranked->take(self::PROJECT_COLUMNS);
        $dropped = $ranked->count() - $shown->count();

        $columns = ['Employee', ...$shown->pluck('name')->all(), 'Booked'];

        $rows = [];

        foreach ($grid['employees'] as $employeeId => $employee) {
            $cells = [$employee];
            $total = 0.0;

            foreach ($shown as $project) {
                $cell = $grid['cells'][$employeeId.':'.$project['id']] ?? null;
                $cells[] = $this->cell($cell);
                $total += (float) ($cell['booked_hours'] ?? 0);
            }

            // The row total is every project's hours, including any column that was dropped — a total that
            // only added up the visible columns would disagree with the person's own timesheet.
            $cells[] = number_format(
                collect($grid['cells'])
                    ->filter(fn (array $cell, string $key): bool => str_starts_with($key, $employeeId.':'))
                    ->sum('booked_hours'),
                1,
            );

            $rows[] = $cells;
        }

        $booked = collect($grid['cells'])->sum('booked_hours');

        return $this->table(
            'PlanVersusActual',
            'Plan vs Actual',
            $this->subtitle('allocation and hours booked in '.$date->format('F Y')),
            $columns,
            // One fixed width per project column, which is what makes the grid wider than the pane and
            // therefore what the scroll wrapper is for. `1fr` columns would shrink to fit and wrap every
            // figure instead.
            'minmax(12rem, 14rem) '.str_repeat('7.5rem ', $shown->count()).'7rem',
            range(1, count($columns) - 1),
            $rows,
            [
                ['label' => 'HOURS BOOKED', 'value' => round((float) $booked, 2), 'accent' => true],
                ['label' => 'PROJECTS', 'value' => (float) $ranked->count(), 'accent' => false],
            ],
            $rows === []
                ? 'NOBODY IS ALLOCATED OR HAS BOOKED TIME IN THIS MONTH'
                : mb_strtoupper(implode(' · ', array_filter([
                    count($rows).' people across '.$ranked->count().' projects',
                    'hours booked over allocation',
                    // Said out loud. A capped report that stays quiet about the cap reads as a complete
                    // one, which is the difference between a summary and a wrong answer.
                    $dropped > 0
                        ? $dropped.' quieter '.($dropped === 1 ? 'project is' : 'projects are').' not shown as columns'
                        : null,
                ]))),
            null,
            'Nobody is allocated to a project or has booked time in this month.',
            wide: true,
        );
    }

    /**
     * One pairing: hours booked, and the allocation that was planned.
     *
     * An em dash for a pairing neither side knows about, so an empty cell in a wide grid is visibly empty
     * rather than possibly unrendered. `—` alone means allocated but nothing booked; a figure with no
     * percentage means booked against a project nobody allocated them to, which is the case somebody
     * should look at.
     *
     * @param  array{allocation_pct: ?float, booked_hours: float}|null  $cell
     */
    private function cell(?array $cell): string
    {
        if ($cell === null) {
            return '—';
        }

        $hours = $cell['booked_hours'] > 0 ? number_format($cell['booked_hours'], 1).'h' : '—';

        return $cell['allocation_pct'] === null
            ? $hours
            : $hours.' / '.number_format($cell['allocation_pct'], 0).'%';
    }

    /** A proportion, or a dash where there is nothing to take a proportion of. */
    private function share(float $part, float $whole): string
    {
        return $whole <= 0 ? '—' : number_format($part / $whole * 100, 1).'%';
    }
}
