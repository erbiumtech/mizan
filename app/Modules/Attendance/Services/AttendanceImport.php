<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A month of attendance from a CSV.
 *
 * **Biometric devices are deliberately out of scope**, and this is the shape that lets
 * one be added later without a data migration: a documented column mapping,
 * `source = import`, and a duplicate-safe unique key on (employee, date). Naming a
 * device vendor in a plan whose author has not seen the device is how you get a driver
 * nobody can test — so the integration point is a file every device can produce.
 *
 * Behaves the way GnuCashImport does, which is the local precedent for an importer:
 * **dry-run first, idempotent on re-import.** A clerk sees what a file will do before
 * it does it, and running the same file twice changes nothing the second time.
 *
 * Expected columns, by header name, case-insensitive:
 *   employee_id, date, status, check_in, check_out, note
 */
class AttendanceImport
{
    /** The header names this understands. Anything else in the file is ignored. */
    public const COLUMNS = ['employee_id', 'date', 'status', 'check_in', 'check_out', 'note'];

    public function __construct(private readonly AttendanceRecorder $recorder) {}

    /**
     * Parse and validate without writing anything.
     *
     * @param  string  $csv  the file's contents
     */
    public function preview(string $csv): AttendanceImportResult
    {
        return $this->process($csv, commit: false);
    }

    public function import(string $csv): AttendanceImportResult
    {
        return $this->process($csv, commit: true);
    }

    private function process(string $csv, bool $commit): AttendanceImportResult
    {
        $rows = $this->parse($csv);

        if ($rows === []) {
            return new AttendanceImportResult(errors: ['The file has no data rows.']);
        }

        // Resolved once rather than per row: a month for forty people is 900 rows, and
        // a lookup each would be 900 queries.
        $employees = Employee::query()
            ->get()
            ->keyBy(fn (Employee $employee): string => strtolower(trim((string) $employee->employee_id)));

        $accepted = 0;
        $errors = [];
        $preview = [];

        foreach ($rows as $line => $row) {
            try {
                $employee = $employees->get(strtolower(trim((string) ($row['employee_id'] ?? ''))));

                if (! $employee) {
                    // Named, not counted. "12 rows failed" sends somebody hunting
                    // through the file; this says which employee code is unknown.
                    $errors[] = "Line {$line}: no employee with code \"{$row['employee_id']}\".";

                    continue;
                }

                $date = Carbon::parse($row['date']);
                $status = $this->normaliseStatus($row['status'] ?? '');

                if ($status === null) {
                    $errors[] = "Line {$line}: \"{$row['status']}\" is not a status this understands.";

                    continue;
                }

                if ($commit) {
                    $this->recorder->record(
                        employee: $employee,
                        date: $date,
                        status: $status,
                        checkIn: $row['check_in'] ?: null,
                        checkOut: $row['check_out'] ?: null,
                        source: AttendanceDay::SOURCE_IMPORT,
                        note: $row['note'] ?: null,
                    );
                } else {
                    // Every check the write would make, and no write — so a
                    // contradiction with approved leave surfaces in the preview rather
                    // than half way through an import that has already written the
                    // rows above it.
                    $this->recorder->validate($employee, $date, $status);
                }

                $accepted++;

                if (count($preview) < 20) {
                    $preview[] = [
                        'employee' => $employee->employee_id,
                        'date' => $date->toDateString(),
                        'status' => $status,
                    ];
                }
            } catch (Throwable $e) {
                // Includes the leave contradiction the recorder refuses. Reported with
                // its line rather than aborting the file: one bad row in a month of 900
                // should not cost the other 899.
                $errors[] = "Line {$line}: {$e->getMessage()}";
            }
        }

        return new AttendanceImportResult(
            accepted: $accepted,
            errors: $errors,
            preview: $preview,
            committed: $commit,
        );
    }

    /**
     * @return array<int, array<string, string>> keyed by 1-based line number
     */
    private function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

        if (count($lines) < 2) {
            return [];
        }

        $header = array_map(
            fn (string $column): string => strtolower(trim($column)),
            str_getcsv(array_shift($lines)),
        );

        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            $row = [];

            foreach (self::COLUMNS as $column) {
                $position = array_search($column, $header, true);
                $row[$column] = $position === false ? '' : trim((string) ($values[$position] ?? ''));
            }

            // +2: one for the header we shifted off, one because humans count from 1.
            $rows[$index + 2] = $row;
        }

        return $rows;
    }

    /**
     * Accepts what a device or a clerk actually writes.
     *
     * `P`, `present` and `Present` are the same answer, and refusing two of the three
     * makes the importer something people give up on and type around.
     */
    private function normaliseStatus(string $status): ?string
    {
        $status = strtolower(str_replace([' ', '-'], '_', trim($status)));

        return match ($status) {
            'p', 'present' => AttendanceDay::STATUS_PRESENT,
            'a', 'absent' => AttendanceDay::STATUS_ABSENT,
            'l', 'leave', 'on_leave' => AttendanceDay::STATUS_ON_LEAVE,
            'h', 'holiday' => AttendanceDay::STATUS_HOLIDAY,
            'w', 'off', 'weekly_off' => AttendanceDay::STATUS_WEEKLY_OFF,
            'hd', 'half', 'half_day' => AttendanceDay::STATUS_HALF_DAY,
            'wfh', 'work_from_home', 'remote' => AttendanceDay::STATUS_WORK_FROM_HOME,
            default => null,
        };
    }
}
