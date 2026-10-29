<?php

namespace App\Modules\Attendance\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A ZKTeco attendance-log export, folded into the importer's own shape — docs/saas-launch-plan.md §7.8.
 *
 * The device software exports one row per PUNCH — `AC-No.`, `Name`, `Time`, `State` — not one per
 * day. This folds them: per person per date, the earliest punch is the check-in and the latest the
 * check-out, a lone punch is a check-in with no check-out, and the status is present. The person is
 * matched by the device's user number against the employee code, which is how an office that enrols
 * staff on the device by their code gets a month in without retyping it.
 *
 * Headers are matched loosely because the exports are not consistent between the device software's
 * versions: `AC-No.` in one, `User ID` in another, `Date/Time` where `Time` was. The day's `State`
 * column, when present, is ignored on purpose: devices in Pakistani offices are mostly set to one
 * state for every punch, so "C/In" on a 6pm punch is the norm rather than an error, and the clock is
 * the fact.
 */
class ZkTecoAttendanceLog
{
    public const PIN_HEADERS = ['ac-no.', 'ac-no', 'acno', 'ac no', 'pin', 'user id', 'userid', 'employee id', 'emp no', 'emp no.', 'no.'];

    public const TIME_HEADERS = ['time', 'date/time', 'date time', 'datetime', 'punch time', 'timestamp'];

    /** The importer's CSV, built from the device's. */
    public static function fold(string $csv): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

        if (count($lines) < 2) {
            return "employee_id,date,status,check_in,check_out,note\n";
        }

        $header = array_map(fn (string $column): string => strtolower(trim($column, " \t\"")), str_getcsv(array_shift($lines)));
        $pin = self::position($header, self::PIN_HEADERS);
        $time = self::position($header, self::TIME_HEADERS);

        if ($pin === null || $time === null) {
            throw new InvalidArgumentException(
                'This does not look like a ZKTeco attendance log: it needs a user-number column ('
                .implode(', ', ['AC-No.', 'User ID', 'PIN']).') and a time column ('
                .implode(', ', ['Time', 'Date/Time']).'). Found: '.implode(', ', $header).'.'
            );
        }

        /** @var array<string, array<string, array<int, Carbon>>> $punches */
        $punches = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            $code = trim((string) ($values[$pin] ?? ''));
            $stamp = trim((string) ($values[$time] ?? ''));

            if ($code === '' || $stamp === '') {
                continue;
            }

            try {
                $at = Carbon::parse($stamp);
            } catch (\Throwable) {
                continue;
            }

            $punches[$code][$at->toDateString()][] = $at;
        }

        $out = ['employee_id,date,status,check_in,check_out,note'];

        foreach ($punches as $code => $days) {
            ksort($days);

            foreach ($days as $date => $times) {
                usort($times, fn (Carbon $a, Carbon $b): int => $a <=> $b);
                $first = $times[0]->format('H:i:s');
                $last = count($times) > 1 ? end($times)->format('H:i:s') : '';

                $out[] = implode(',', [$code, $date, 'present', $first, $last, count($times).' punch'.(count($times) === 1 ? '' : 'es')]);
            }
        }

        return implode("\n", $out)."\n";
    }

    /** @param  array<int, string>  $header  @param  array<int, string>  $candidates */
    private static function position(array $header, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $position = array_search($candidate, $header, true);

            if ($position !== false) {
                return (int) $position;
            }
        }

        return null;
    }
}
