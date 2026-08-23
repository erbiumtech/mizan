<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Puts one company's employee codes into its own sequence, from one upward.
 *
 * Codes used to be built as `EMP-`.$user->id. Users are landlord records shared across
 * every company, so a single global counter numbered them all and no company's codes
 * began at one or ran consecutively. `Employee::nextEmployeeId()` fixed that for new
 * employees; this brings the ones already on file into line.
 *
 * Separate from the command that drives it because the command is `TenantAware` — it
 * switches tenant databases, which the test suite cannot do, since it deliberately runs
 * every tenant in one database. The decisions worth testing are all here.
 */
class EmployeeCodeRenumbering
{
    /**
     * What renumbering would do, without doing any of it.
     *
     * Oldest joiner first, so the lowest code belongs to the longest-serving person.
     * Employees with no joining date sort last rather than first, where a missing date
     * would otherwise read as "joined before everybody".
     *
     * @return array{mapping: Collection<int, array{id: int, name: string, joined: ?string, from: string, to: string}>, skipped: Collection<int, string>}
     */
    public function plan(string $prefix = Employee::CODE_PREFIX): array
    {
        // `user` eager-loaded because the name usually lives on the linked landlord
        // account rather than on the employee row, and a mapping of blank names is not
        // something anybody can check before agreeing to it.
        $employees = Employee::query()
            ->with('user')
            ->orderByRaw('date_of_joining IS NULL')
            ->orderBy('date_of_joining')
            ->orderBy('id')
            ->get(['id', 'user_id', 'employee_id', 'name', 'date_of_joining']);

        [$renumber, $skipped] = $employees->partition(
            fn (Employee $e): bool => $this->isGeneratedCode((string) $e->employee_id, $prefix)
        );

        $mapping = $renumber->values()->map(fn (Employee $employee, int $index): array => [
            'id' => $employee->id,
            'name' => $employee->fullName(),
            'joined' => $employee->date_of_joining?->toDateString(),
            'from' => (string) $employee->employee_id,
            'to' => $prefix.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
        ]);

        return [
            'mapping' => $mapping,
            'skipped' => $skipped->map(fn (Employee $e): string => (string) $e->employee_id)->values(),
        ];
    }

    /**
     * Write the codes that actually change, in two passes, because `employee_id` is unique.
     *
     * Renumbering shuffles codes among the rows that already hold them, so a direct update
     * collides the moment a target is still occupied by a row not yet reached — two people
     * who simply need to swap would fail the whole run. Parking every affected row on a
     * value nothing else can hold — its own id, behind a character no generated or
     * hand-typed code uses — makes the order irrelevant.
     *
     * Updated through the query builder, which does not fire the model's hooks. That is the
     * intent, not an oversight: a code rewrite is not a change of job facts and must not
     * deposit a row in the employee's job history.
     *
     * @param  Collection<int, array{id: int, from: string, to: string}>  $changing
     */
    public function apply(Collection $changing): int
    {
        if ($changing->isEmpty()) {
            return 0;
        }

        DB::connection((new Employee)->getConnectionName())->transaction(function () use ($changing): void {
            foreach ($changing as $row) {
                Employee::whereKey($row['id'])->update(['employee_id' => '~renumbering-'.$row['id']]);
            }

            foreach ($changing as $row) {
                Employee::whereKey($row['id'])->update(['employee_id' => $row['to']]);
            }
        });

        return $changing->count();
    }

    /**
     * Only codes of the generated shape are touched. A company that types its own —
     * `STAFF-7`, `EMP-2024-CONTRACT` — meant them, and the same rule keeps the two sets
     * from colliding: every code that could equal a newly assigned one is in the set being
     * rewritten, so a code left alone can never be a code assigned.
     */
    private function isGeneratedCode(string $code, string $prefix): bool
    {
        if (! Str::startsWith($code, $prefix)) {
            return false;
        }

        $suffix = Str::after($code, $prefix);

        return $suffix !== '' && ctype_digit($suffix);
    }
}
