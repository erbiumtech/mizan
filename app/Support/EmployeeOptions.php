<?php

namespace App\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search results for the employee pickers.
 *
 * Every employee select labels its options with `display_label` — "EMP-1 - Ali
 * Raza" — but a Filament relationship select searches only its *title
 * attribute*, here `employee_id`. So the list shows names while the search
 * matches codes, and typing the name you can see returns nothing.
 *
 * Names live on the landlord `users` table and employees in the tenant one, so
 * the match cannot be a join: the user ids are resolved on the landlord
 * connection first, then the employees by foreign key. Same reasoning as
 * {@see LandlordUserColumn}, which does this for table columns.
 */
class EmployeeOptions
{
    /** Keeps a broad search from returning the entire company. */
    public const LIMIT = 50;

    /**
     * @param  (callable(Builder): Builder)|null  $scope  the same constraint the
     *                                                    select's relationship() applies, so search
     *                                                    cannot reveal employees the list would hide
     * @return array<int, string> employee id => display label
     */
    public static function search(string $search, ?callable $scope = null, int $limit = self::LIMIT): array
    {
        $term = trim($search);

        if ($term === '') {
            return [];
        }

        $userIds = User::query()
            ->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"))
            ->pluck('id')
            ->all();

        $query = Employee::query()->with('user');

        if ($scope) {
            $query = $scope($query) ?? $query;
        }

        return $query
            ->where(fn (Builder $inner) => $inner
                ->where('employee_id', 'like', "%{$term}%")
                ->when($userIds !== [], fn (Builder $q) => $q->orWhereIn('user_id', $userIds)))
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->getKey() => $employee->display_label])
            ->all();
    }

    /**
     * The scope the employee-keyed resources use: own record plus downline,
     * unless the user holds a privileged role.
     *
     * @return callable(Builder): Builder
     */
    public static function accessibleScope(): callable
    {
        return fn (Builder $query): Builder => app(EmployeeAccess::class)
            ->scopeAccessibleEmployees($query, auth()->user());
    }
}
