<?php

namespace App\Support;

use Closure;

/**
 * What the company holds about one person — docs/saas-launch-plan.md §14.8.
 *
 * A subject-access request is answered across every module that keeps anything about
 * an employee, and no module can see the others. So each contributes its own slice
 * by name and the export is their sum. A module that is not licensed contributes
 * nothing, which is correct rather than a gap: a company without payroll holds no
 * payslips to disclose.
 *
 * The employee is typed `object` for the reason {@see VerificationChecks} gives —
 * this is shared ground and names no module's model. Each closure knows what it was
 * handed because it registered itself.
 */
final class SubjectAccess
{
    /** @var array<string, array{closure: Closure, sort: int}> */
    private static array $slices = [];

    /** @param  Closure(object): array<string, mixed>  $slice */
    public static function register(string $label, Closure $slice, int $sort = 100): void
    {
        self::$slices[$label] = ['closure' => $slice, 'sort' => $sort];
    }

    /**
     * Every slice, keyed by its label.
     *
     * A slice that throws is reported as such rather than taking the export down: a
     * disclosure that is missing one section and says so is answerable; one that 500s
     * is a request nobody can answer at all.
     *
     * @return array<string, mixed>
     */
    public static function forEmployee(object $employee): array
    {
        $slices = self::$slices;
        uasort($slices, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $out = [];

        foreach ($slices as $label => $slice) {
            try {
                $out[$label] = ($slice['closure'])($employee);
            } catch (\Throwable $e) {
                report($e);
                $out[$label] = ['error' => 'This section could not be read: '.$e->getMessage()];
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function labels(): array
    {
        return array_keys(self::$slices);
    }

    public static function flush(): void
    {
        self::$slices = [];
    }
}
