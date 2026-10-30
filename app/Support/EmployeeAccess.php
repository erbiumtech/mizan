<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the set of employees a user is allowed to see under the manager
 * hierarchy: their own employee record plus everyone in their downline (all
 * reports, transitively).
 *
 * The descendant walk loads the current tenant's `id => manager_id` map once
 * and does an in-PHP BFS — no recursive CTE, so it works identically on SQLite
 * (tests) and MySQL. Employee counts per tenant are small, so this is cheap.
 *
 * Results are memoized per (tenant, user) for the lifetime of the request so a
 * single `whereIn` in each resource never re-runs the walk.
 */
class EmployeeAccess
{
    /** Roles that bypass hierarchy scoping and see every employee's data. */
    public const PRIVILEGED_ROLES = ['Administrator', 'Accountant', 'Manager', 'CEO'];

    /** Whether the user sees all employees (privileged) rather than just their downline. */
    public function isPrivileged(?User $user): bool
    {
        return $user !== null && ($user->isSuperAdmin() || $user->hasAnyRole(self::PRIVILEGED_ROLES));
    }

    /**
     * Constrain a query over the `employees` table to the user's accessible set
     * (own + downline), unless they are privileged. Used to scope both resource
     * queries and the option lists of filters/selects so a manager never sees
     * employees outside their downline.
     *
     * @param  string  $column  the employees-table column to filter on (usually `id`)
     */
    public function scopeAccessibleEmployees(Builder $query, ?User $user, string $column = 'id'): Builder
    {
        if ($user && ! $this->isPrivileged($user)) {
            $query->whereIn($column, $this->accessibleEmployeeIds($user)->all());
        }

        return $query;
    }

    /** @var array<string, Collection<int, int>> employee-id sets, keyed by tenant+user */
    protected array $employeeIdCache = [];

    /** @var array<string, Collection<int, int>> user-id sets, keyed by tenant+user */
    protected array $userIdCache = [];

    /** @var array<string, Employee|null> the signed-in person's own record, keyed by tenant+user */
    protected array $ownRecordCache = [];

    /**
     * The employee record behind a user, or nothing.
     *
     * **Memoised because several screens ask it on every page of the panel.** The
     * per-person screens — Confirm Your Details, My Email Signature, the forms that
     * default a picker to "me" — each resolve the signed-in person's own record, and
     * Filament evaluates every one of their `canAccess()` methods while it builds the
     * navigation. Measured on the dashboard: six `select exists` and two `select *`
     * against `employees`, all asking the same question of the same user.
     *
     * Returns the model without relations. A caller that needs one does `loadMissing()`
     * on what comes back, which then loads once for the request rather than once per
     * screen — and lazy loading is prevented outside production, so it has to be asked
     * for rather than assumed.
     *
     * Null is a cached answer too: somebody with no employee record — an accountant
     * brought in for the books, the owner — would otherwise be looked up every time.
     */
    public function employeeFor(?User $user): ?Employee
    {
        if ($user === null) {
            return null;
        }

        $key = $this->cacheKey($user);

        if (! array_key_exists($key, $this->ownRecordCache)) {
            $this->ownRecordCache[$key] = Employee::query()->where('user_id', $user->getKey())->first();
        }

        return $this->ownRecordCache[$key];
    }

    /** Whether this person has an employee record here at all. */
    public function isEmployee(?User $user): bool
    {
        return $this->employeeFor($user) !== null;
    }

    /**
     * The signed-in person's own employee id — what a picker defaulted to "me" wants.
     *
     * Every create form that opens on the person filling it in — a leave request, an
     * expense claim, a timesheet line — used to ask the table for this itself, and a
     * page carrying two such pickers asked twice. They go through the memo now, which
     * the navigation has usually already filled by the time a form is built.
     */
    public function ownEmployeeId(?User $user): ?int
    {
        $id = $this->employeeFor($user)?->getKey();

        return $id === null ? null : (int) $id;
    }

    /**
     * Employee ids the user may access: their own record + all descendants.
     * Empty when the user has no employee record in this tenant.
     *
     * @return Collection<int, int>
     */
    public function accessibleEmployeeIds(User $user): Collection
    {
        $key = $this->cacheKey($user);

        return $this->employeeIdCache[$key] ??= $this->resolveEmployeeIds($user);
    }

    /**
     * User ids behind the accessible employees — for resources (like MPR) that
     * key on `user_id` rather than `employee_id`.
     *
     * @return Collection<int, int>
     */
    public function accessibleUserIds(User $user): Collection
    {
        $key = $this->cacheKey($user);

        return $this->userIdCache[$key] ??= Employee::query()
            ->whereIn('id', $this->accessibleEmployeeIds($user)->all())
            // Staff with no login — a household's driver or cook — have a null
            // user_id, and the cast below would turn that into user 0. Excluded
            // here rather than filtered afterwards so the id list only ever
            // contains real users.
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    protected function resolveEmployeeIds(User $user): Collection
    {
        $self = Employee::query()->where('user_id', $user->id)->value('id');

        if ($self === null) {
            return collect();
        }

        return $this->subtreeEmployeeIds((int) $self);
    }

    /**
     * The given employee's id plus every descendant (transitive reports).
     * BFS over the tenant's reporting map — cycle-safe (a visited node is never
     * re-enqueued).
     *
     * @return Collection<int, int>
     */
    public function subtreeEmployeeIds(int $employeeId): Collection
    {
        $childrenByManager = Employee::query()
            ->whereNotNull('manager_id')
            // Only ids are read here. Employee::$with would otherwise eager-load
            // users against a select that carries no user_id to match them on.
            ->without('user')
            ->get(['id', 'manager_id'])
            ->groupBy('manager_id')
            ->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        $subtree = [$employeeId];
        $queue = [$employeeId];

        while ($queue) {
            $current = array_shift($queue);

            foreach ($childrenByManager[$current] ?? [] as $childId) {
                if (! in_array($childId, $subtree, true)) {
                    $subtree[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return collect($subtree)->values();
    }

    protected function cacheKey(User $user): string
    {
        $tenant = Company::current()?->getKey() ?? 'default';

        return $tenant.':'.$user->id;
    }
}
