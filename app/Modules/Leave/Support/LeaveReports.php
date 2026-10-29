<?php

namespace App\Modules\Leave\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalance;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;

/**
 * Leave utilisation — docs/saas-launch-plan.md §14.10.
 *
 * **The question is what the entitlement is for, not what it is worth.**
 * `LeaveLiability` already answers the money one: what unused encashable leave would
 * cost if everybody took it. This answers the people one: who is using their leave
 * and who is not.
 *
 * Both halves of "not" are worth seeing. Somebody at 10% has a year's holiday waiting
 * to be taken in December, which is a staffing problem in December; somebody at 110%
 * is on negative balance, which is a payroll problem now.
 *
 * The arithmetic is {@see LeaveBalance}'s and not a second copy of it. Credited,
 * taken, pending and remaining all mean exactly what they mean on an employee's own
 * balance screen, which is the only way a figure here can be argued with.
 */
class LeaveReports
{
    use ReportShapes;

    /** Below this, somebody is not taking their leave. */
    private const UNDER_USED = 25.0;

    public function utilisation(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $balances = app(LeaveBalance::class);

        $types = LeaveType::query()->where('is_active', true)->orderBy('sort')->orderBy('label')->get();
        $employees = Employee::query()->with('user')->where('is_active', true)->orderBy('employee_id')->get();

        $rows = [];
        $credited = 0.0;
        $taken = 0.0;
        $underUsed = 0;

        foreach ($employees as $employee) {
            $employeeCredited = 0.0;
            $employeeTaken = 0.0;
            $cells = [];

            foreach ($types as $type) {
                $breakdown = $balances->for($employee, $type, $date);

                // No entitlement generated for this window is not zero days: it is a
                // question nobody has answered yet, and showing it as 0 of 0 would read
                // as somebody with no leave rather than a year nobody opened.
                if ($breakdown === null || $breakdown->isUngenerated()) {
                    $cells[] = '—';

                    continue;
                }

                $employeeCredited += $breakdown->credited();
                $employeeTaken += $breakdown->taken;

                $cells[] = $this->trim($breakdown->taken).' / '.$this->trim($breakdown->credited());
            }

            if ($employeeCredited <= 0) {
                continue;
            }

            $share = $employeeTaken / $employeeCredited * 100;
            $underUsed += $share < self::UNDER_USED ? 1 : 0;
            $credited += $employeeCredited;
            $taken += $employeeTaken;

            $rows[] = [
                'sort' => $share,
                'row' => [
                    (string) ($employee->display_label ?? 'Employee #'.$employee->getKey()),
                    ...$cells,
                    $this->trim($employeeCredited),
                    $this->trim($employeeTaken),
                    $this->trim($employeeCredited - $employeeTaken),
                    number_format($share, 0).'%',
                ],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $rows = array_map(fn (array $entry): array => $entry['row'], $rows);

        $typeColumns = $types->map(fn (LeaveType $type): string => (string) $type->label)->all();
        $firstFigure = 1 + count($typeColumns);

        return $this->table(
            'LeaveUtilisation',
            'Leave Utilisation',
            $this->subtitle('leave years covering '.$asOf),
            ['Employee', ...$typeColumns, 'Credited', 'Taken', 'Left', 'Used'],
            'minmax(0, 1fr)'.str_repeat(' 10rem', count($typeColumns)).' 8rem 8rem 8rem 6rem',
            range($firstFigure, $firstFigure + 3),
            $rows,
            [
                ['label' => 'DAYS CREDITED', 'value' => round($credited, 1), 'accent' => false],
                ['label' => 'DAYS TAKEN', 'value' => round($taken, 1), 'accent' => true],
                // The number a company acts on: people sitting on a year's leave.
                ['label' => 'UNDER '.(int) self::UNDER_USED.'% USED', 'value' => (float) $underUsed, 'accent' => false],
            ],
            $rows === []
                ? 'NO LEAVE YEAR IS OPEN FOR ANYBODY'
                : mb_strtoupper($this->trim($taken).' of '.$this->trim($credited).' days taken · '
                    .($credited > 0 ? number_format($taken / $credited * 100, 0) : '0').'% across '.count($rows).' people'),
            null,
            'No leave year is open for anybody yet. Open one under Leave Balances.',
            // Wide: one column per leave type, and a company with six types needs to scroll
            // rather than have the names squeezed to nothing.
            wide: count($typeColumns) > 3,
        );
    }

    /** Days as somebody writes them: 1.5, and 12 rather than 12.0. */
    private function trim(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1), '0'), '.');
    }
}
