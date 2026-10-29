<?php

namespace App\Modules\Lifecycle\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use Illuminate\Support\Collection;

/**
 * Which documents a company insists on, and what it will accept — docs/saas-launch-plan.md §14.8.
 *
 * Nothing is required until somebody says so, which is the shipped state: a company
 * that never opens the screen has the document list it always had, with no
 * compliance report full of red.
 *
 * The formats and the size limit are the upload's, not the law's. They are here
 * rather than in the form because "we only accept PDFs" is a company's decision and
 * changing it should not be a deployment.
 */
final class DocumentRequirements
{
    /** What an upload may be, where the company has not narrowed it. */
    public const DEFAULT_FORMATS = ['pdf', 'jpg', 'png'];

    public const DEFAULT_MAX_KB = 4096;

    /** The kinds this company insists on, as kind => label. @return array<string, string> */
    public static function required(): array
    {
        $wanted = setting('lifecycle.documents.required', []);
        $wanted = is_array($wanted) ? $wanted : [];

        return array_intersect_key(EmployeeDocument::KINDS_LABELLED, array_flip($wanted));
    }

    /** @return array<int, string> */
    public static function formats(): array
    {
        $formats = setting('lifecycle.documents.formats', self::DEFAULT_FORMATS);

        return array_values(array_intersect(is_array($formats) ? $formats : [], self::DEFAULT_FORMATS)) ?: self::DEFAULT_FORMATS;
    }

    public static function maxKb(): int
    {
        $max = (int) setting('lifecycle.documents.max_kb', self::DEFAULT_MAX_KB);

        return $max > 0 ? $max : self::DEFAULT_MAX_KB;
    }

    /**
     * The state of one employee's required documents.
     *
     * A kind counts as held when there is a document of that kind that has not been
     * rejected and has not expired. Verification is reported separately, because
     * "they have not sent it" and "nobody has looked at it yet" are different jobs
     * and only the first one is the employee's.
     *
     * @return array<string, string> kind => 'missing' | 'rejected' | 'expired' | 'unverified' | 'verified'
     */
    public static function stateFor(Employee $employee, ?Collection $documents = null): array
    {
        // Queried from this side rather than through a relation on `Employee`: Employees
        // does not depend on Lifecycle, and putting a `documents()` relation on the
        // employee would be exactly that dependency, the wrong way round.
        $documents ??= EmployeeDocument::query()->where('employee_id', $employee->getKey())->get();
        $state = [];

        foreach (array_keys(self::required()) as $kind) {
            $ofKind = $documents->where('kind', $kind);

            $state[$kind] = match (true) {
                $ofKind->isEmpty() => 'missing',
                $ofKind->contains(fn (EmployeeDocument $d): bool => $d->verified_at !== null && ! $d->hasExpired()) => 'verified',
                $ofKind->contains(fn (EmployeeDocument $d): bool => $d->rejected_at === null && ! $d->hasExpired()) => 'unverified',
                $ofKind->contains(fn (EmployeeDocument $d): bool => $d->hasExpired()) => 'expired',
                default => 'rejected',
            };
        }

        return $state;
    }

    /** @return array<int, string> the labels of what is not held at all */
    public static function missingFor(Employee $employee, ?Collection $documents = null): array
    {
        $required = self::required();

        return collect(self::stateFor($employee, $documents))
            ->reject(fn (string $state): bool => $state === 'verified' || $state === 'unverified')
            ->keys()
            ->map(fn (string $kind): string => $required[$kind] ?? $kind)
            ->values()
            ->all();
    }
}
