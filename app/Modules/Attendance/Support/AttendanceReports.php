<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRegister;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;

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
                ? $disagree.($disagree === 1 ? ' payslip' : ' payslips').' prorated on different paid days'
                : null,
        ])));
    }
}
