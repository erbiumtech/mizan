<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRegister;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The monthly attendance register — `docs/reports-expansion-plan.md` Phase 3.1.
 *
 * An employee × day grid, with the four figures payroll reads underneath each row: paid days, loss of pay,
 * late minutes and overtime hours.
 *
 * **Its value is the comparison, and the plan says so: "the same figure payroll prorates on, which makes
 * disagreement between the two visible."** A payslip stores the `paid_days` it prorated on, so a register
 * that computes the same figure from the same calendar either agrees with the payslip or has found something.
 * The report states the disagreements and counts them; it does not state agreement per row, because a column
 * of ticks is not worth thirty-one columns of space.
 *
 * **One letter per day.** Thirty-one columns of "present" would be unreadable and thirty-one of a coloured
 * dot would be unprintable. The legend is in the note, because a legend in the help is a legend nobody reads
 * while looking at the grid.
 */
class AttendanceReports
{
    use ReportShapes;

    /**
     * One letter per status.
     *
     * `·` for a day nobody marked, deliberately not a blank: an unmarked day is counted as *worked* by
     * `AttendanceMonth::paidDays()` — "a day nobody recorded is not a day anybody missed" — so it is a fact
     * about the month with a consequence for pay, and a blank cell would read as nothing having happened.
     */
    private const LETTERS = [
        AttendanceDay::STATUS_PRESENT => 'P',
        AttendanceDay::STATUS_ABSENT => 'A',
        AttendanceDay::STATUS_ON_LEAVE => 'L',
        AttendanceDay::STATUS_HOLIDAY => 'H',
        AttendanceDay::STATUS_WEEKLY_OFF => 'O',
        AttendanceDay::STATUS_HALF_DAY => '½',
        AttendanceDay::STATUS_WORK_FROM_HOME => 'W',
        AttendanceDay::STATUS_NOT_MARKED => '·',
    ];

    public function register(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $data = app(AttendanceRegister::class)->forMonth($date->year, $date->month);

        $columns = ['Employee'];

        foreach ($data['days'] as $day) {
            // The day of the month only. A full date per column would be six characters wide thirty-one
            // times over, and the month is already on the subtitle.
            $columns[] = (string) (int) Carbon::parse($day)->format('j');
        }

        $columns = [...$columns, 'Paid', 'LOP', 'Late', 'OT'];

        $rows = [];
        $paid = 0.0;
        $lop = 0.0;
        $late = 0;
        $overtime = 0.0;
        $incomplete = 0;
        $disagree = 0;

        foreach ($data['employees'] as $row) {
            $cells = [(string) $row['employee']->display_label];

            foreach ($data['days'] as $day) {
                $cells[] = self::LETTERS[$row['statuses'][$day]] ?? '?';
            }

            $cells[] = number_format($row['paid'], 1);
            $cells[] = $row['lop'] > 0 ? number_format($row['lop'], 1) : '—';
            $cells[] = $row['late'] > 0 ? number_format($row['late']) : '—';
            $cells[] = $row['overtime'] > 0 ? number_format($row['overtime'], 1) : '—';

            $rows[] = $cells;

            $paid += $row['paid'];
            $lop += $row['lop'];
            $late += $row['late'];
            $overtime += $row['overtime'];
            $incomplete += $row['incomplete'];

            if ($row['payslip_paid'] !== null && abs($row['payslip_paid'] - $row['paid']) >= 0.01) {
                $disagree++;
            }
        }

        return $this->table(
            'AttendanceRegister',
            'Attendance Register',
            $this->subtitle($date->format('F Y')),
            $columns,
            'minmax(12rem, 16rem) '.str_repeat('2rem ', count($data['days'])).'6rem 6rem 6rem 6rem',
            range(count($data['days']) + 1, count($columns) - 1),
            $rows,
            [
                ['label' => 'PAID DAYS', 'value' => round($paid, 1), 'accent' => true],
                ['label' => 'LOSS OF PAY', 'value' => round($lop, 1), 'accent' => false],
            ],
            $this->registerNote($rows, $incomplete, $disagree),
            $rows === [] ? null : [
                'Total — '.count($rows).' people',
                ...array_fill(0, count($data['days']), ''),
                number_format($paid, 1),
                number_format($lop, 1),
                number_format($late),
                number_format($overtime, 1),
            ],
            'Nobody was employed in this month.',
            wide: true,
        );
    }

    /**
     * The legend, and the two things that make the figures untrustworthy.
     *
     * Unmarked days come first because they *inflate* paid days — they are counted as worked — so a month
     * with many of them reads as a good month and is really an unfilled one. A disagreement with a payslip
     * comes second and is the more serious of the two: it means pay has already been calculated on a figure
     * this register does not reproduce.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function registerNote(array $rows, int $incomplete, int $disagree): string
    {
        if ($rows === []) {
            return 'NOBODY WAS EMPLOYED IN THIS MONTH';
        }

        return mb_strtoupper(implode(' · ', array_filter([
            'P present, A absent, L leave, H holiday, O weekly off, ½ half day, W from home, · not marked',
            $incomplete > 0
                ? $incomplete.' days not marked, counted as worked'
                : null,
            $disagree > 0
                ? $disagree.' '.Str::plural('payslip', $disagree).' prorated on different paid days'
                : null,
        ])));
    }

    /**
     * Who is late, how often and by how much — docs/saas-launch-plan.md §14.10.
     *
     * The register already carries late minutes, in a grid thirty-one columns wide that
     * answers a different question: what happened to this person this month. This one
     * answers who to talk to, which means ordering by the figure rather than by name and
     * leaving out everybody who was never late.
     *
     * **Occasions and minutes both, because they are different behaviours.** Twelve
     * marks of five minutes and one of an hour are the same hour and not the same
     * conversation.
     */
    public function lateMarks(string $asOf): array
    {
        $month = Carbon::parse($asOf);
        [$from, $to] = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];

        $days = AttendanceDay::query()
            ->with('employee.user')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('late_minutes', '>', 0)
            ->get();

        $rows = [];
        $occasions = 0;
        $minutes = 0;

        foreach ($days->groupBy('employee_id') as $employeeId => $forEmployee) {
            $employeeMinutes = (int) $forEmployee->sum('late_minutes');
            $worst = (int) $forEmployee->max('late_minutes');

            $occasions += $forEmployee->count();
            $minutes += $employeeMinutes;

            $rows[] = [
                'sort' => $employeeMinutes,
                'row' => [
                    (string) ($forEmployee->first()->employee?->display_label ?? 'Employee #'.$employeeId),
                    (string) ($forEmployee->first()->employee?->department ?: '—'),
                    number_format($forEmployee->count()),
                    $this->duration($employeeMinutes),
                    $this->duration($worst),
                    $this->duration((int) round($employeeMinutes / max(1, $forEmployee->count()))),
                ],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);
        $rows = array_map(fn (array $entry): array => $entry['row'], $rows);

        return $this->table(
            'LateMarks',
            'Late Marks',
            $this->subtitle($month->format('F Y')),
            ['Employee', 'Department', 'Times late', 'Total', 'Worst', 'Average'],
            'minmax(0, 1fr) 11rem 7rem 7rem 7rem 7rem',
            [2, 3, 4, 5],
            $rows,
            [
                ['label' => 'PEOPLE', 'value' => (float) count($rows), 'accent' => false],
                ['label' => 'HOURS LOST', 'value' => round($minutes / 60, 1), 'accent' => true],
            ],
            $rows === []
                ? 'NOBODY WAS LATE THIS MONTH'
                : mb_strtoupper(count($rows).' people, '.$occasions.' occasions, '.$this->duration($minutes).' in total'),
            null,
            'Nobody was marked late this month.',
        );
    }

    /**
     * Who was absent, and whether anybody knew — docs/saas-launch-plan.md §14.10.
     *
     * **Absent means absent, not on leave.** A day of approved leave is a day the
     * company agreed to; an absent day is one it did not, and putting them in one column
     * is how an absence report stops being read. Leave is counted beside it for context
     * and nothing more.
     *
     * Days nobody marked are counted too, in their own column. A month with forty
     * unmarked days has no absence figure worth trusting, and saying so is more use than
     * a confident zero.
     */
    public function absences(string $asOf): array
    {
        $month = Carbon::parse($asOf);
        [$from, $to] = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];

        $days = AttendanceDay::query()
            ->with('employee.user')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', [AttendanceDay::STATUS_ABSENT, AttendanceDay::STATUS_ON_LEAVE, AttendanceDay::STATUS_NOT_MARKED])
            ->get();

        $rows = [];
        $absent = 0;
        $unmarked = 0;

        foreach ($days->groupBy('employee_id') as $employeeId => $forEmployee) {
            $counts = $forEmployee->countBy('status');
            $employeeAbsent = (int) ($counts[AttendanceDay::STATUS_ABSENT] ?? 0);
            $employeeUnmarked = (int) ($counts[AttendanceDay::STATUS_NOT_MARKED] ?? 0);

            if ($employeeAbsent === 0 && $employeeUnmarked === 0) {
                continue;
            }

            $absent += $employeeAbsent;
            $unmarked += $employeeUnmarked;

            $rows[] = [
                'sort' => $employeeAbsent * 100 + $employeeUnmarked,
                'row' => [
                    (string) ($forEmployee->first()->employee?->display_label ?? 'Employee #'.$employeeId),
                    (string) ($forEmployee->first()->employee?->department ?: '—'),
                    $employeeAbsent > 0 ? number_format($employeeAbsent) : '—',
                    $employeeUnmarked > 0 ? number_format($employeeUnmarked) : '—',
                    number_format((int) ($counts[AttendanceDay::STATUS_ON_LEAVE] ?? 0)),
                ],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);
        $rows = array_map(fn (array $entry): array => $entry['row'], $rows);

        return $this->table(
            'Absences',
            'Absences',
            $this->subtitle($month->format('F Y')),
            ['Employee', 'Department', 'Absent', 'Not marked', 'On leave'],
            'minmax(0, 1fr) 11rem 7rem 8rem 8rem',
            [2, 3, 4],
            $rows,
            [
                ['label' => 'ABSENT DAYS', 'value' => (float) $absent, 'accent' => true],
                // Its own tile because it is a different problem: these are days nobody
                // recorded, and they pay as present.
                ['label' => 'DAYS NOT MARKED', 'value' => (float) $unmarked, 'accent' => false],
            ],
            $rows === []
                ? 'EVERY DAY THIS MONTH IS MARKED AND NOBODY WAS ABSENT'
                : mb_strtoupper($absent.' absent day(s)'.($unmarked > 0 ? ', and '.$unmarked.' nobody marked' : '')),
            null,
            'Nobody was absent and every day is marked.',
        );
    }

    /** Minutes as somebody would say them. */
    private function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours.'h' : $hours.'h '.$rest.'m';
    }
}
